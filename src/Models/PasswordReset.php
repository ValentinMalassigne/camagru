<?php
// PasswordReset model: all SQL for the password_resets table. A reset token
// is random (random_bytes(32), hex), stored only as its SHA-256 hash, valid
// for one hour and single use. Times are computed by PostgreSQL (now()),
// which avoids any PHP/DB timezone drift.
//
// Single use is enforced atomically: the claim marks the row used with a
// conditional UPDATE, so two concurrent requests can never both consume the
// same token.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class PasswordReset
{
    /**
     * Create a reset token for a user, expiring one hour from now. Any
     * previous outstanding token for the same user is invalidated first so
     * only one reset link is ever valid (limiting the blast radius of a
     * leaked older email).
     */
    public static function create(int $userId, string $tokenHash): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $invalidate = $pdo->prepare(
                'UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL'
            );
            $invalidate->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, now() + interval \'1 hour\')'
            );
            $insert->execute([$userId, $tokenHash]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Find the outstanding reset row for a token hash: not used yet and not
     * expired. Returns null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > now()'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atomically claim a reset row (mark it used). Returns true when this
     * call won the claim, false when the token was already used — this is
     * what makes the token single use even under two concurrent requests.
     */
    public static function claim(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE password_resets SET used_at = now() WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Invalidate every outstanding reset token of a user (used after the
     * password was successfully changed, or from the account page later).
     */
    public static function invalidateForUser(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);
    }
}
