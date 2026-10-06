<?php
// EditorController: the editor page and its capture/upload endpoint
// (spec section 4.4), authenticated only.
//
// POST /editor/capture receives BOTH picture sources with the same field
// names, so they share one server pipeline (spec section 8):
// - the webcam capture, sent by editor.js with fetch and an
//   X-Requested-With header — answered with JSON {id, url};
// - the plain HTML upload form, sent without any JavaScript — answered
//   with a flash message and a redirect, so the editor works end to end
//   even with JS disabled.
//
// Authorization: both actions check the session user server-side; a visitor
// is redirected to the login page with a friendly message.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Image;
use App\Services\ImageComposer;
use App\Services\ImageException;

class EditorController
{
    /**
     * GET /editor — the editor page. Authenticated users only.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in to use the editor.');
            return Response::redirect('/login');
        }

        $html = View::renderPage('pages/editor.php', [
            'title'    => 'Editor',
            // Overlay whitelist (id => filename) shown as the picker.
            'overlays' => require APP_ROOT . '/config/overlays.php',
            // Side section: all the user's previous pictures, newest first.
            'images'   => Image::allByUser((int) $user['id']),
        ]);
        return Response::make($html);
    }

    /**
     * POST /editor/capture — composite and store a picture from the webcam
     * (JSON) or from the upload form (redirect + flash).
     *
     * @param array<string, string> $params
     */
    public function capture(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', 'Please log in to use the editor.');
            return Response::redirect('/login');
        }

        // Our own fetch call identifies itself with this header; the plain
        // form does not, so it gets the no-JavaScript flow.
        $wantsJson = $request->header('X-Requested-With') === 'XMLHttpRequest';

        if (!Csrf::verify($request)) {
            return $this->failure($wantsJson, 'Your session expired. Please reload the page and try again.');
        }

        $file = $request->file('photo');
        if ($file === null) {
            return $this->failure($wantsJson, 'Please choose a picture.');
        }

        try {
            // The overlay id is validated inside the pipeline (whitelist).
            $filename = ImageComposer::compose($file, (int) ($request->post('overlay') ?? '0'));
        } catch (ImageException $e) {
            // The user gets the safe message, the console gets the cause too:
            // some rejections are server-side problems (corrupt overlay,
            // unwritable uploads dir) that must not stay invisible.
            app_log("upload rejected: " . $e->getMessage());
            return $this->failure($wantsJson, $e->getMessage());
        }

        $id = Image::create((int) $user['id'], $filename);

        if ($wantsJson) {
            return Response::json(['id' => $id, 'url' => '/uploads/' . $filename]);
        }

        Session::flash('success', 'Your picture has been saved.');
        return Response::redirect('/editor');
    }

    /**
     * Report a rejected request: a JSON error for the webcam fetch, a flash
     * message and a redirect for the plain form.
     */
    private function failure(bool $wantsJson, string $message): Response
    {
        if ($wantsJson) {
            return Response::json(['error' => $message], 422);
        }
        Session::flash('error', $message);
        return Response::redirect('/editor');
    }
}
