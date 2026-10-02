<?php
// CommentController: posting a comment on one picture (spec section 4.3).
//
// Security notes:
// - POST + CSRF token only: a comment cannot be forged from another page.
// - Authentication is checked server-side; a visitor is sent to the login
//   page instead of getting an error.
// - The comment is tied to the session's user id, never to a client-
//   supplied value, so nobody can post as someone else.
// - The body is validated (non-empty, capped with a UTF-8-aware length
//   check) and stored raw; it is escaped with e() in the view, which is
//   the single XSS defence for all user-controlled output.
//
// The author notification follows the two account preferences: the email
// is sent when "notify me on new comments" is on, and when the author
// comments on their own picture only if "also notify me when I comment on
// my own images" is on too (that second toggle has no effect while the
// first is off). A mail failure is logged and never breaks the request.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\SiteUrl;
use App\Core\Validator;
use App\Core\View;
use App\Models\Comment;
use App\Models\Image;
use App\Models\User;
use App\Services\Mail\AppMailer;

class CommentController
{
    /** Maximum comment length in characters (UTF-8-aware check via mbstring). */
    private const MAX_BODY_LENGTH = 1000;

    /**
     * POST /images/{id}/comments — validate and store a comment, then
     * redirect back to the picture.
     *
     * The same request serves two callers: a plain form POST (no JavaScript)
     * gets a flash message and a redirect, an XHR (X-Requested-With, sent by
     * image.js for the AJAX bonus) gets JSON — the rendered comment on
     * success (server-side partial, so escaping stays on the server), the
     * validation error with status 422 otherwise. Authentication, CSRF and
     * the author notification are identical in both modes.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params = []): Response
    {
        $wantsJson = $request->header('X-Requested-With') === 'XMLHttpRequest';

        $user = Auth::user();
        if ($user === null) {
            if ($wantsJson) {
                return Response::json(['error' => 'Please log in to comment.'], 401);
            }
            Session::flash('error', 'Please log in to comment.');
            return Response::redirect('/login');
        }

        $image = Image::findById((int) ($params['id'] ?? '0'));
        if ($image === null) {
            // Also checked after CSRF below; 404 either way, and the
            // redirect target does not exist, so answer here already.
            throw new NotFoundException('Image not found');
        }

        if (!Csrf::verify($request)) {
            if ($wantsJson) {
                return Response::json(['error' => 'Your session expired. Please reload the page and try again.'], 403);
            }
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/images/' . (int) $image['id']);
        }

        $body = trim((string) $request->post('body', ''));
        $validator = new Validator();
        $validator->required('body', $body);
        $validator->maxLength('body', $body, self::MAX_BODY_LENGTH);
        if ($validator->fails()) {
            if ($wantsJson) {
                return Response::json(['error' => $validator->errors()['body']], 422);
            }
            // The body is not re-filled on purpose (the field is the last
            // thing before the button, an over-limit text is rare); the
            // flash names the exact problem.
            Session::flash('error', $validator->errors()['body']);
            return Response::redirect('/images/' . (int) $image['id']);
        }

        Comment::create((int) $image['id'], (int) $user['id'], $body);
        $this->notifyAuthor($request, $image, $user, $body);

        if ($wantsJson) {
            return Response::json([
                // The comment is rendered by the same partial as on the
                // page, so the inserted entry is identical to the others
                // and escaping stays server-side.
                'html'  => View::renderPartial('partials/comment.php', [
                    'comment' => [
                        'username' => (string) $user['username'],
                        'date'     => date('j M Y, H:i'), // same format as GalleryController
                        'body'     => $body,
                    ],
                ]),
                'count' => Comment::countByImage((int) $image['id']),
            ]);
        }

        Session::flash('success', 'Your comment has been posted.');
        return Response::redirect('/images/' . (int) $image['id']);
    }

    /**
     * Send the notification email when the two account preferences allow
     * it (see the class notes). Any failure is logged and swallowed: the
     * comment is already stored, and a mail problem must never break the
     * request (spec section 4.3).
     *
     * @param Request               $request The incoming request (for the email base URL).
     * @param array<string, mixed>  $image   The commented picture's row (author id and picture id).
     * @param array<string, mixed>  $user    The commenting user's row (session user, never client data).
     * @param string                $body    The comment body (already validated).
     */
    private function notifyAuthor(Request $request, array $image, array $user, string $body): void
    {
        $authorId = (int) $image['user_id'];
        $author = User::findById($authorId);
        if ($author === null || !(bool) $author['notify_on_comment']) {
            return;
        }
        // The author commenting their own picture only triggers the email
        // when the second toggle is on as well.
        if ($authorId === (int) $user['id'] && !(bool) $author['notify_on_own_comment']) {
            return;
        }

        $mailer = new AppMailer();
        $sent = $mailer->sendCommentNotification(
            (string) $author['email'],
            (string) $author['username'],
            (string) $user['username'],
            (int) $image['id'],
            $body,
            SiteUrl::base($request)
        );
        if (!$sent) {
            app_log('comment-notification mail failed for user ' . $authorId . ' on image ' . (int) $image['id']);
        }
    }
}
