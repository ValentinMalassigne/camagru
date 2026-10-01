<?php
// Image model: all SQL touching the images table lives here (models hold the
// queries, controllers stay thin). Every query uses bound parameters.
//
// The uploaded files themselves live in the uploads volume, not in the DB:
// the schema's ON DELETE CASCADE removes the likes and comments of a deleted
// image, but it can never touch the filesystem. Removing the file is always
// done by the application (see removeFile), right after the row is deleted.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class Image
{
    /**
     * Find an image by primary key.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM images WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * All images of one user, newest first (the editor sidebar, spec 4.4).
     * id DESC breaks ties for images created in the same instant.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allByUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, filename, created_at FROM images
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$userId]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Store a composited picture. The filename comes from ImageComposer
     * (random, no client input).
     *
     * @return int The new image id.
     */
    public static function create(int $userId, string $filename): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO images (user_id, filename) VALUES (?, ?)');
        $stmt->execute([$userId, $filename]);
        return (int) $pdo->lastInsertId('images_id_seq');
    }

    /**
     * Delete one image row. Its likes and comments are removed by the
     * schema's ON DELETE CASCADE; the uploaded file is not — call removeFile.
     */
    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM images WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Every filename uploaded by one user, used to remove the files when the
     * account is deleted (the DB cascade cannot do it).
     *
     * @return array<int, string>
     */
    public static function filenamesByUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT filename FROM images WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        /** @var array<int, string> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Remove one uploaded file from the uploads directory. Returns false when
     * the file is gone already or could not be removed; callers log failures
     * but never fail the request on them (a leftover file is harmless
     * compared to a broken page).
     */
    public static function removeFile(string $filename): bool
    {
        $path = APP_UPLOAD_DIR . '/' . $filename;
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }
}
