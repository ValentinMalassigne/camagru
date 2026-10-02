<?php
// GalleryController: the public gallery (spec section 4.3) — the listing on
// the home page and the image detail page. The gallery is visible to
// everyone; liking and commenting are handled by their own controllers and
// require a logged-in user.
//
// Pagination security note: the ?page= value never reaches SQL as text.
// It is normalised to an integer between 1 and the last page, and LIMIT /
// OFFSET are bound parameters (see Image::page), so no injection is possible
// and no offset can walk outside the real page range.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Comment;
use App\Models\Image;
use App\Models\Like;

class GalleryController
{
    /** Images per gallery page (spec section 4.3: 6). */
    private const PER_PAGE = 6;

    /**
     * GET / — the public gallery, newest first, paginated.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $totalPages = max(1, (int) ceil(Image::countAll() / self::PER_PAGE));
        $page = self::normalizePage($request->query('page'), $totalPages);

        $html = View::renderPage('pages/gallery.php', [
            'title'      => 'Camagru',
            'images'     => Image::page(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
        return Response::make($html);
    }

    /**
     * GET /images/{id} — the public detail page of one picture, with its
     * like state and its comments. An unknown id is a 404, not an error.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $image = Image::findWithAuthor((int) ($params['id'] ?? '0'));
        if ($image === null) {
            throw new NotFoundException('Image not found');
        }

        $user = Auth::user();
        // Did the current visitor already like this picture? Only a
        // logged-in user can have a like row.
        $liked = $user !== null && Like::exists((int) $user['id'], (int) $image['id']);

        $html = View::renderPage('pages/image.php', [
            'title'    => 'Picture by ' . (string) $image['username'],
            'image'    => $image,
            'liked'    => $liked,
            // The picture's date is formatted here so the view stays
            // markup-only; comments get the same treatment below.
            'date'     => self::formatDate((string) $image['created_at']),
            'comments' => array_map(function (array $comment): array {
                $comment['date'] = self::formatDate((string) $comment['created_at']);
                return $comment;
            }, Comment::allByImage((int) $image['id'])),
        ]);
        return Response::make($html);
    }

    /**
     * Normalise a raw ?page= value to an integer between 1 and the last
     * page: anything missing, non-numeric, zero or beyond the end falls back
     * to a valid page, so the value is always safe to use as an offset.
     */
    private static function normalizePage(?string $raw, int $totalPages): int
    {
        if ($raw === null || !ctype_digit($raw) || (int) $raw < 1) {
            return 1;
        }
        return min((int) $raw, $totalPages);
    }

    /**
     * Format a PostgreSQL timestamp for display (e.g. "2 Oct 2026, 14:05").
     * UTC is used on purpose: the app has no per-user timezone, so a single
     * deterministic format avoids any ambiguous "today" rendering.
     */
    private static function formatDate(string $timestamp): string
    {
        $unix = strtotime($timestamp);
        return $unix === false ? '' : date('j M Y, H:i', $unix);
    }
}
