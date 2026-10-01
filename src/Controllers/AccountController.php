<?php
// AccountController: the account page (spec section 4.2), authenticated only.
// One page, three small forms (profile, password, notifications), all POST to
// /account with an "action" field and their own CSRF token. Validation rules
// are exactly those of registration (Validator), and uniqueness checks ignore
// the user's own row so saving unchanged values never fails.
//
// Authorization: every action checks the session user server-side; a visitor
// is redirected to the login page with a friendly message (spec section 4.4).

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\Image;
use App\Models\PasswordReset;
use App\Models\User;
use PDOException;

class AccountController
{
    /**
     * GET /account — the account page. Authenticated users only.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in to access your account page.');
            return Response::redirect('/login');
        }

        return $this->showAccount([], []);
    }

    /**
     * POST /account — dispatch on the "action" hidden field: profile
     * (username, email), password (current + new) or notifications (two
     * checkboxes).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in to access your account page.');
            return Response::redirect('/login');
        }

        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/account');
        }

        $action = $request->post('action', '');
        switch ($action) {
            case 'profile':
                return $this->updateProfile($request, $user);
            case 'password':
                return $this->updatePassword($request, $user);
            case 'notifications':
                return $this->updateNotifications($request, $user);
            case 'delete':
                return $this->deleteAccount($request, $user);
        }

        // Unknown action: show the page again, nothing changed.
        return Response::redirect('/account');
    }

    /**
     * Change username and/or email. Uniqueness is case-insensitive and ignores
     * the user's own row; the unique index also backs a race condition.
     *
     * @param array<string, mixed> $user
     */
    private function updateProfile(Request $request, array $user): Response
    {
        $username = trim($request->post('username', ''));
        $email = trim($request->post('email', ''));
        $selfId = (int) $user['id'];

        $validator = new Validator();
        $validator
            ->username('username', $username)
            ->email('email', $email);

        if ($validator->passes() && User::usernameTakenByOther($username, $selfId)) {
            $validator->addError('username', 'This username is already taken.');
        }
        if ($validator->passes() && User::emailTakenByOther($email, $selfId)) {
            $validator->addError('email', 'An account with this email already exists.');
        }

        if ($validator->fails()) {
            return $this->showAccount($validator->errors(), [
                'username' => $username,
                'email'    => $email,
            ]);
        }

        try {
            User::updateProfile($selfId, $username, $email);
        } catch (PDOException $e) {
            // Race: unique index fired between the check and the update.
            if ($e->getCode() === '23505' || strpos($e->getMessage(), 'duplicate key') !== false) {
                return $this->showAccount(
                    ['username' => 'This username or email is already taken.'],
                    ['username' => $username, 'email' => $email]
                );
            }
            throw $e;
        }

        Session::flash('success', 'Your profile has been updated.');
        return Response::redirect('/account');
    }

    /**
     * Change the password: the current one is required (re-authentication, so
     * a hijacked session cannot silently take the account over), the new one
     * follows the registration rules. Outstanding reset tokens are
     * invalidated so a link requested earlier can no longer be used.
     *
     * @param array<string, mixed> $user
     */
    private function updatePassword(Request $request, array $user): Response
    {
        $current = (string) $request->post('current_password', '');
        $new = (string) $request->post('new_password', '');

        $validator = new Validator();
        $validator->required('current_password', $current);
        $validator->password('new_password', $new);

        if ($validator->passes() && !password_verify($current, (string) $user['password_hash'])) {
            $validator->addError('current_password', 'Your current password is incorrect.');
        }

        if ($validator->fails()) {
            return $this->showAccount($validator->errors(), []);
        }

        $selfId = (int) $user['id'];
        User::updatePasswordHash($selfId, password_hash($new, PASSWORD_DEFAULT));
        PasswordReset::invalidateForUser($selfId);

        Session::flash('success', 'Your password has been changed.');
        return Response::redirect('/account');
    }

    /**
     * Update the notification preferences from the two checkboxes: an
     * unchecked box is simply absent from the POST body, so "present" is on.
     * The own-comment toggle only matters when the main one is on (checked at
     * send time, phase 4).
     *
     * @param array<string, mixed> $user
     */
    private function updateNotifications(Request $request, array $user): Response
    {
        $onComment = $request->post('notify_on_comment') !== null;
        $onOwnComment = $request->post('notify_on_own_comment') !== null;

        User::updateNotifications((int) $user['id'], $onComment, $onOwnComment);

        Session::flash('success', 'Your notification settings have been saved.');
        return Response::redirect('/account');
    }

    /**
     * Delete the account (user-requested feature, beyond the spec). The
     * password is required so a hijacked session cannot silently destroy the
     * account; the images, likes, comments and reset tokens of the account
     * are removed by the schema's ON DELETE CASCADE. The effect is instant:
     * the row is deleted and the session fully destroyed.
     *
     * @param array<string, mixed> $user
     */
    private function deleteAccount(Request $request, array $user): Response
    {
        $password = (string) $request->post('delete_password', '');

        $validator = new Validator();
        $validator->required('delete_password', $password);
        if ($validator->passes() && !password_verify($password, (string) $user['password_hash'])) {
            $validator->addError('delete_password', 'Your password is incorrect.');
        }
        if ($validator->fails()) {
            return $this->showAccount($validator->errors(), []);
        }

        // The uploaded FILES must be removed by the application: the schema
        // cascade deletes the rows, but it cannot touch the filesystem.
        $filenames = Image::filenamesByUser((int) $user['id']);

        User::delete((int) $user['id']);

        // Best effort, after the row deletion: a leftover file is logged but
        // must never block or fail the account deletion.
        foreach ($filenames as $filename) {
            if (!Image::removeFile($filename)) {
                app_log('Could not remove uploaded file: ' . $filename);
            }
        }

        // Instant effect: the session cannot survive the account it refers to.
        Auth::logout();
        Session::restart();
        Session::flash('success', 'Your account has been deleted.');
        return Response::redirect('/');
    }

    /**
     * Render the account page with optional errors and old profile input.
     *
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function showAccount(array $errors, array $old): Response
    {
        // Fresh row: after a change the page must show the stored values.
        $user = Auth::user();
        $html = View::renderPage('pages/account.php', [
            'title'  => 'My account',
            'user'   => $user,
            'errors' => $errors,
            'old'    => $old,
        ]);
        return Response::make($html);
    }
}
