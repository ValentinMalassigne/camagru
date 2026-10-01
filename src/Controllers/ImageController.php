<?php
// ImageController: deleting one of the user's own pictures (spec section 4.4).
//
// Security notes:
// - POST + CSRF token only, so the action cannot be forged from another page.
// - The ownership is checked server-side: a forged request can never delete
//   someone else's picture, even if the id is known.
// - Both the DB row and the uploaded FILE are removed: likes and comments
//   cascade in the database, but the filesystem is not covered by any
//   cascade, so the application deletes the file itself.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Image;

class ImageController
{
    /**
     * POST /images/{id}/delete — remove one of the current user's pictures.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in first.');
            return Response::redirect('/login');
        }

        if (!Csrf::verify($request)) {
            Session::flash('error', 'Your session expired. Please try again.');
            return Response::redirect('/editor');
        }

        // Unknown or malformed id: the picture does not exist for this
        // caller, so a 404 page (not an error page) is the honest answer.
        $image = Image::findById((int) ($params['id'] ?? '0'));
        if ($image === null) {
            throw new NotFoundException('Image not found');
        }

        if ((int) $image['user_id'] !== (int) $user['id']) {
            Session::flash('error', 'You can only delete your own pictures.');
            return Response::redirect('/editor');
        }

        Image::delete((int) $image['id']);
        if (!Image::removeFile((string) $image['filename'])) {
            // The row is gone; a leftover file is logged but never blocks
            // the request.
            app_log('Could not remove uploaded file: ' . (string) $image['filename']);
        }

        Session::flash('success', 'The picture has been deleted.');
        return Response::redirect('/editor');
    }
}
