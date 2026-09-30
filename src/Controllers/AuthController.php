<?php
// AuthController: registration, email confirmation, login, logout and
// password reset (spec section 4.2). The account page arrives in the last
// step of phase 2. All forms are validated server-side; POST actions check
// the CSRF token; failures reveal no more than needed.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\SiteUrl;
use App\Core\Validator;
use App\Core\View;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Mail\AppMailer;
use PDOException;

class AuthController
{
    /**
     * A bcrypt hash of the fixed string "camagru-login-dummy" (not a
     * credential, no secret). Verifying against it when the username is
     * unknown costs the same as verifying a real password, so response
     * timing cannot be used to enumerate existing usernames.
     */
    private const DUMMY_HASH = '$2y$10$BVisZbBj.vrTqLF41ZmV/.qfLFCevzcnFzqez20AC.yES7o5KMvMq';
    /**
     * GET /register — the registration form.
     *
     * @param array<string, string> $params
     */
    public function registerForm(Request $request, array $params = []): Response
    {
        return $this->showRegisterForm([], []);
    }

    /**
     * POST /register — validate, create the account, send the confirmation
     * email, redirect. On any validation problem the form is re-rendered with
     * the errors and the previously entered values.
     *
     * @param array<string, string> $params
     */
    public function register(Request $request, array $params = []): Response
    {
        if (!Csrf::verify($request)) {
            // Expired form / forged request: generic message, try again.
            Session::flash('error', 'Your session expired. Please fill the form again.');
            return Response::redirect('/register');
        }

        $username = trim($request->post('username', ''));
        $email = trim($request->post('email', ''));
        $password = (string) $request->post('password', '');

        // Same validation rules as everywhere else (Validator).
        $validator = new Validator();
        $validator
            ->username('username', $username)
            ->email('email', $email)
            ->password('password', $password);

        // Uniqueness, case-insensitive (unique indexes back this up too).
        if ($validator->passes() && User::findByUsername($username) !== null) {
            $validator->addError('username', 'This username is already taken.');
        }
        if ($validator->passes() && User::findByEmail($email) !== null) {
            $validator->addError('email', 'An account with this email already exists.');
        }

        if ($validator->fails()) {
            return $this->showRegisterForm($validator->errors(), [
                'username' => $username,
                'email'    => $email,
            ]);
        }

        // Create the account: hashed password, random single-use confirmation
        // token (only its SHA-256 hash is stored, the raw token goes by email).
        $token = bin2hex(random_bytes(32));
        try {
            $userId = User::create(
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                hash('sha256', $token)
            );
        } catch (PDOException $e) {
            // Race: another request inserted the same username/email between
            // our check and the insert (unique violation, SQLSTATE 23505).
            if ($e->getCode() === '23505' || strpos($e->getMessage(), 'duplicate key') !== false) {
                return $this->showRegisterForm(
                    ['username' => 'This username or email is already taken.'],
                    ['username' => $username, 'email' => $email]
                );
            }
            throw $e;
        }

        // Send the confirmation email. A failure is logged and shown to the
        // user, but never breaks the request (the account exists either way).
        // The link's base URL follows the host the user actually browsed
        // from, when that host is allowlisted (see SiteUrl).
        $mailer = new AppMailer();
        if (!$mailer->sendVerification($email, $username, $token, SiteUrl::base($request))) {
            app_log("verification mail failed for user $userId");
            Session::flash(
                'error',
                'Your account was created, but the confirmation email could not be sent. Please try registering again later.'
            );
            return Response::redirect('/');
        }

        Session::flash('success', 'Account created! Check your emails and click the confirmation link to activate it.');
        return Response::redirect('/');
    }

    /**
     * GET /login — the login form.
     *
     * @param array<string, string> $params
     */
    public function loginForm(Request $request, array $params = []): Response
    {
        return $this->showLoginForm([]);
    }

    /**
     * POST /login — authenticate with username + password. Any failure gives
     * the same generic message (no user enumeration: an unknown username and
     * a wrong password are indistinguishable, in message and in timing).
     * Successful login rotates the session id (anti-fixation) and the
     * password is transparently rehashed if the algorithm parameters
     * changed since it was stored.
     *
     * @param array<string, string> $params
     */
    public function login(Request $request, array $params = []): Response
    {
        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/login');
        }

        $username = trim($request->post('username', ''));
        $password = (string) $request->post('password', '');

        $validator = new Validator();
        $validator
            ->required('username', $username)
            ->required('password', $password);
        if ($validator->fails()) {
            return $this->showLoginForm($validator->errors());
        }

        $user = User::findByUsername($username);
        if ($user === null) {
            // Verify against a dummy hash so the timing of an unknown
            // username matches a wrong password (anti-enumeration).
            password_verify($password, self::DUMMY_HASH);
            return $this->loginFailed();
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return $this->loginFailed();
        }

        // Correct credentials: the account owner is allowed to learn the
        // account's state, but an unverified account still cannot log in.
        if (!(bool) $user['is_verified']) {
            return $this->showLoginForm([
                'username' => 'This account is not verified yet. Check your emails for the confirmation link.',
            ]);
        }

        // Rehash transparently if the stored hash's parameters are outdated.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        Auth::login($user);
        Session::flash('success', 'Welcome back, ' . $user['username'] . '!');
        return Response::redirect('/');
    }

    /**
     * POST /logout — one-click logout, CSRF-protected POST from the header
     * on every page. Destroys the whole session.
     *
     * @param array<string, string> $params
     */
    public function logout(Request $request, array $params = []): Response
    {
        if (!Csrf::verify($request)) {
            // A forged logout does nothing; just send the visitor home.
            return Response::redirect('/');
        }
        Auth::logout();
        // Full destroy, then a fresh session carrying only the flash.
        Session::restart();
        Session::flash('success', 'You have been logged out.');
        return Response::redirect('/');
    }

    /**
     * Render the login form after a failed attempt: same generic message
     * whatever the cause.
     *
     * @param array<string, string> $errors
     */
    private function loginFailed(): Response
    {
        return $this->showLoginForm([
            'username' => 'Invalid username or password.',
        ]);
    }

    /**
     * Render the login form with optional field errors.
     *
     * @param array<string, string> $errors
     */
    private function showLoginForm(array $errors): Response
    {
        $html = View::renderPage('pages/login.php', [
            'title'  => 'Login',
            'errors' => $errors,
        ]);
        return Response::make($html);
    }

    /**
     * GET /forgot-password — the email request form.
     *
     * @param array<string, string> $params
     */
    public function forgotPasswordForm(Request $request, array $params = []): Response
    {
        $html = View::renderPage('pages/forgot_password.php', [
            'title'  => 'Forgot password',
            'errors' => [],
        ]);
        return Response::make($html);
    }

    /**
     * POST /forgot-password — create a single-use reset token and email it.
     * The response is identical whether or not the email belongs to an
     * account (no user enumeration); a mail failure is logged and also
     * changes nothing about the response.
     *
     * @param array<string, string> $params
     */
    public function forgotPassword(Request $request, array $params = []): Response
    {
        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/forgot-password');
        }

        $email = trim($request->post('email', ''));
        $validator = new Validator();
        $validator->email('email', $email);
        if ($validator->fails()) {
            // A format error reveals nothing about existing accounts.
            return $this->showForgotForm($validator->errors());
        }

        $user = User::findByEmail($email);
        if ($user !== null) {
            // Only the hash of the token is stored; the raw token travels
            // by email only. Creating a token also invalidates any previous
            // outstanding one for this account.
            $token = bin2hex(random_bytes(32));
            PasswordReset::create((int) $user['id'], hash('sha256', $token));

            $mailer = new AppMailer();
            if (!$mailer->sendPasswordReset(
                (string) $user['email'],
                (string) $user['username'],
                $token,
                SiteUrl::base($request)
            )) {
                app_log('password-reset mail failed for user ' . (int) $user['id']);
            }
        }

        // Same message and same redirect in every case.
        Session::flash('info', 'If an account exists with this email, a reset link has been sent. It is valid for one hour.');
        return Response::redirect('/login');
    }

    /**
     * GET /reset-password?token= — the new-password form. Only a valid,
     * unused, unexpired token shows the form; anything else gets the same
     * generic message.
     *
     * @param array<string, string> $params
     */
    public function resetPasswordForm(Request $request, array $params = []): Response
    {
        $token = $request->query('token', '');
        $reset = $this->findValidReset($token);
        if ($reset === null) {
            return $this->invalidResetLink();
        }

        $html = View::renderPage('pages/reset_password.php', [
            'title'  => 'Reset password',
            'errors' => [],
            'token'  => $token,
        ]);
        return Response::make($html);
    }

    /**
     * POST /reset-password — set the new password (same rules as
     * registration). The token is claimed atomically before the update, so
     * it is single use even under concurrent requests; using it also kills
     * every other outstanding token of the account.
     *
     * @param array<string, string> $params
     */
    public function resetPassword(Request $request, array $params = []): Response
    {
        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/forgot-password');
        }

        $token = $request->post('token', '');
        $password = (string) $request->post('password', '');

        $validator = new Validator();
        $validator->password('password', $password);
        $reset = $this->findValidReset($token);
        if ($reset === null) {
            return $this->invalidResetLink();
        }
        if ($validator->fails()) {
            $html = View::renderPage('pages/reset_password.php', [
                'title'  => 'Reset password',
                'errors' => $validator->errors(),
                'token'  => $token,
            ]);
            return Response::make($html);
        }

        // Single use: this conditional UPDATE must win to proceed.
        if (!PasswordReset::claim((int) $reset['id'])) {
            return $this->invalidResetLink();
        }

        User::updatePasswordHash((int) $reset['user_id'], password_hash($password, PASSWORD_DEFAULT));
        PasswordReset::invalidateForUser((int) $reset['user_id']);

        Session::flash('success', 'Your password has been reset. You can now log in.');
        return Response::redirect('/login');
    }

    /**
     * Look up the outstanding reset row for a raw token, timing-safely.
     *
     * @return array<string, mixed>|null
     */
    private function findValidReset(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $reset = PasswordReset::findValidByTokenHash($hash);
        if ($reset === null || !hash_equals((string) $reset['token_hash'], $hash)) {
            return null;
        }
        return $reset;
    }

    /**
     * Common generic response for a missing, used or expired reset token.
     */
    private function invalidResetLink(): Response
    {
        Session::flash('error', 'This reset link is invalid or has expired. Please request a new one.');
        return Response::redirect('/forgot-password');
    }

    /**
     * Render the forgot-password form with optional errors.
     *
     * @param array<string, string> $errors
     */
    private function showForgotForm(array $errors): Response
    {
        $html = View::renderPage('pages/forgot_password.php', [
            'title'  => 'Forgot password',
            'errors' => $errors,
        ]);
        return Response::make($html);
    }

    /**
     * GET /verify?token= — confirm the account with the emailed token.
     * A missing, unknown or already-used token gives the same generic
     * message (no information about which accounts exist).
     *
     * @param array<string, string> $params
     */
    public function verify(Request $request, array $params = []): Response
    {
        $invalid = function (): Response {
            Session::flash('error', 'This confirmation link is invalid or has already been used.');
            return Response::redirect('/');
        };

        $token = $request->query('token', '');
        if ($token === '') {
            return $invalid();
        }

        // The DB stores the SHA-256 hash; compare with hash_equals as well
        // (timing-safe, spec section 7).
        $hash = hash('sha256', $token);
        $user = User::findByVerificationTokenHash($hash);
        if ($user === null || !hash_equals((string) $user['verification_token_hash'], $hash)) {
            return $invalid();
        }

        User::markVerified((int) $user['id']);
        Session::flash('success', 'Your email is confirmed! You can now log in.');
        return Response::redirect('/');
    }

    /**
     * Render the registration form with optional field errors and old input.
     *
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function showRegisterForm(array $errors, array $old): Response
    {
        $html = View::renderPage('pages/register.php', [
            'title'  => 'Register',
            'errors' => $errors,
            'old'    => $old,
        ]);
        return Response::make($html);
    }
}
