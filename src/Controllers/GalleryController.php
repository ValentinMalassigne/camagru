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
use App\Core\SiteUrl;
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
     * A plain browser request renders the full page; an XHR request
     * (X-Requested-With, sent by gallery.js for the infinite-scroll bonus)
     * gets the next page's cards as JSON instead: the items are rendered by
     * the same partial as the full page, so escaping stays server-side.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $totalPages = max(1, (int) ceil(Image::countAll() / self::PER_PAGE));
        $page = self::normalizePage($request->query('page'), $totalPages);
        $images = Image::page(self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
            return Response::json([
                'page'       => $page,
                'totalPages' => $totalPages,
                'hasMore'    => $page < $totalPages,
                'html'       => View::renderPartial('partials/gallery_items.php', [
                    'images' => $images,
                ]),
            ]);
        }

        $html = View::renderPage('pages/gallery.php', [
            'title'      => 'Camagru',
            'images'     => $images,
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
        return Response::make($html);
    }

    /**
     * GET /images/{id} — the public detail page of one picture, with its
     * like state, its comments and the social share links (bonus). An
     * unknown id is a 404, not an error.
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

        $comments = array_map(function (array $comment): array {
            $comment['date'] = self::formatDate((string) $comment['created_at']);
            return $comment;
        }, Comment::allByImage((int) $image['id']));

        $html = View::renderPage('pages/image.php', [
            'title'    => 'Picture by ' . (string) $image['username'],
            'image'    => $image,
            'liked'    => $liked,
            // The picture's date is formatted here so the view stays
            // markup-only; comments get the same treatment above.
            'date'     => self::formatDate((string) $image['created_at']),
            'comments' => $comments,
            'shares'   => self::shareLinks($request, $image),
            // Open Graph meta for the share links (built here, escaped,
            // inserted by the layout).
            'headMeta' => self::ogMeta($request, $image),
        ]);
        return Response::make($html);
    }

    /**
     * Social share links (bonus): plain share URLs, built server-side and
     * URL-encoded. No JS library, no API key — the networks themselves render
     * the preview from the Open Graph meta tags.
     *
     * @param array<string, mixed> $image
     * @return array<int, array<string, string>> Each: label + absolute share URL.
     */
    private static function shareLinks(Request $request, array $image): array
    {
        $url = SiteUrl::base($request) . '/images/' . (int) $image['id'];
        $text = 'A picture by ' . (string) $image['username'] . ' on Camagru';

        return [
            ['label' => 'Share on X', 'url' =>
                'https://twitter.com/intent/tweet?url=' . rawurlencode($url) .
                '&text=' . rawurlencode($text)],
            ['label' => 'Share on Facebook', 'url' =>
                'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url)],
            ['label' => 'Share on Reddit', 'url' =>
                'https://www.reddit.com/submit?url=' . rawurlencode($url)],
        ];
    }

    /**
     * Open Graph meta tags for the detail page (consumed by the share
     * targets). Escaped here; the layout inserts the string as built.
     *
     * @param array<string, mixed> $image
     */
    private static function ogMeta(Request $request, array $image): string
    {
        $url = SiteUrl::base($request) . '/images/' . (int) $image['id'];
        $imageUrl = SiteUrl::base($request) . '/uploads/' . (string) $image['filename'];
        $title = 'Picture by ' . (string) $image['username'] . ' — Camagru';

        return '<meta property="og:title" content="' . e_attr($title) . '">' . "\n" .
            '    <meta property="og:url" content="' . e_attr($url) . '">' . "\n" .
            '    <meta property="og:image" content="' . e_attr($imageUrl) . '">';
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
