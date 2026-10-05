<?php
// Like model: all SQL touching the likes table lives here (models hold the
// queries, controllers stay thin). Every query uses bound parameters.
// The composite primary key (user_id, image_id) makes a double like
// impossible at the database level, so the toggle is race-free: an "add"
// that collides with a concurrent like is simply a no-op there.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class Like
{
    /**
     * Has the given user already liked the given image?
     */
    public static function exists(int $userId, int $imageId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM likes WHERE user_id = ? AND image_id = ?'
        );
        $stmt->execute([$userId, $imageId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Add one like. A concurrent duplicate is ignored (the primary key
     * rejects it), so the toggle stays correct under racing requests.
     */
    public static function add(int $userId, int $imageId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO likes (user_id, image_id) VALUES (?, ?) ON CONFLICT DO NOTHING'
        );
        $stmt->execute([$userId, $imageId]);
    }

    /**
     * Remove one like. Removing a like that is already gone is a no-op.
     */
    public static function remove(int $userId, int $imageId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM likes WHERE user_id = ? AND image_id = ?'
        );
        $stmt->execute([$userId, $imageId]);
    }

    /**
     * Number of likes of one image, shown next to the like button.
     */
    public static function countByImage(int $imageId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM likes WHERE image_id = ?'
        );
        $stmt->execute([$imageId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Turn the current user's like on an image on or off. Returns true when
     * the image ends up liked by the user (the button label and flash
     * message depend on it).
     */
    public static function toggle(int $userId, int $imageId): bool
    {
        if (self::exists($userId, $imageId)) {
            self::remove($userId, $imageId);
            return false;
        }
        self::add($userId, $imageId);
        return true;
    }
}
