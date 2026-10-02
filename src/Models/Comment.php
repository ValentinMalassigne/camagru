<?php
// Comment model: all SQL touching the comments table lives here (models hold
// the queries, controllers stay thin). Every query uses bound parameters.
// The body is stored exactly as validated by the controller (trimmed, length
// capped); it is escaped with e() when rendered, never on storage.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class Comment
{
    /**
     * Store one comment. The body was already validated and trimmed by the
     * controller; user and image ids come from the session and the route, so
     * no client-controlled value reaches the query unbound.
     *
     * @return int The new comment id.
     */
    public static function create(int $imageId, int $userId, string $body): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO comments (image_id, user_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$imageId, $userId, $body]);
        return (int) $pdo->lastInsertId('comments_id_seq');
    }

    /**
     * All comments of one image, oldest first (natural reading order),
     * with each author's username for display. Everything is escaped with
     * e() in the view.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allByImage(int $imageId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.body, c.created_at, u.username
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.image_id = ?
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute([$imageId]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }
}
