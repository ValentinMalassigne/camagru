<?php
// LikeController: toggling the current user's like on one picture
// (spec section 4.3).
//
// Security notes:
// - POST + CSRF token only: a like cannot be forged from another page.
// - Authentication is checked server-side; a visitor is sent to the login
//   page instead of getting an error.
// - The like is tied to the session's user id, never to a client-supplied
//   value, so one user can never create or remove another user's like.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Image;
use App\Models\Like;

class LikeController
{
    /**
     * POST /images/{id}/like — add or remove the current user's like.
     *
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in to like pictures.');
            return Response::redirect('/login');
        }

        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/images/' . (int) ($params['id'] ?? '0'));
        }

        // Unknown or malformed id: the picture does not exist for this
        // caller, so a 404 page is the honest answer.
        $image = Image::findById((int) ($params['id'] ?? '0'));
        if ($image === null) {
            throw new NotFoundException('Image not found');
        }

        $liked = Like::toggle((int) $user['id'], (int) $image['id']);
        Session::flash('success', $liked ? 'You liked this picture.' : 'You unliked this picture.');
        return Response::redirect('/images/' . (int) $image['id']);
    }
}
