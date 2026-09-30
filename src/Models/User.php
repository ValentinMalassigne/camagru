<?php
// User model: all SQL touching the users table lives here (models hold the
// queries, controllers stay thin). Every query uses bound parameters.
// Usernames and emails are matched case-insensitively with lower(), backed
// by the unique indexes defined in db/schema.sql.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class User
{
    /**
     * Find a user by username, case-insensitive.
     *
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE lower(username) = lower(?)'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Find a user by email address, case-insensitive.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE lower(email) = lower(?)'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Find a user by primary key (used by Auth for the session's user id).
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Create a new user. Password is already hashed by the controller; the
     * verification token is stored as a SHA-256 hash (the raw token only
     * travels by email, never in the DB).
     *
     * @return int The new user id.
     */
    public static function create(string $username, string $email, string $passwordHash, string $verificationTokenHash): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, verification_token_hash)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, $email, $passwordHash, $verificationTokenHash]);
        return (int) $pdo->lastInsertId('users_id_seq');
    }

    /**
     * Find an unverified user by verification token hash. Verified users
     * have no token left, so they can never match again.
     *
     * @return array<string, mixed>|null
     */
    public static function findByVerificationTokenHash(string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE verification_token_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Mark a user as verified and drop the verification token (single use).
     */
    public static function markVerified(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET is_verified = true, verification_token_hash = NULL WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Replace a user's password hash (new password, or a transparent rehash
     * on login when the algorithm's default parameters changed).
     */
    public static function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $id]);
    }

    /**
     * Is a username already used by another account (case-insensitive)?
     * Excludes the given user's own row so keeping one's username is never
     * reported as a conflict.
     */
    public static function usernameTakenByOther(string $username, int $selfId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM users WHERE lower(username) = lower(?) AND id <> ?'
        );
        $stmt->execute([$username, $selfId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Is an email already used by another account (case-insensitive)?
     * Excludes the given user's own row.
     */
    public static function emailTakenByOther(string $email, int $selfId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM users WHERE lower(email) = lower(?) AND id <> ?'
        );
        $stmt->execute([$email, $selfId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Update username and email. The email takes effect immediately with no
     * re-verification (spec section 4.2).
     */
    public static function updateProfile(int $id, string $username, string $email): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET username = ?, email = ? WHERE id = ?'
        );
        $stmt->execute([$username, $email, $id]);
    }

    /**
     * Delete an account. Images, likes, comments and password-reset rows are
     * removed by the schema's ON DELETE CASCADE.
     */
    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Update the two notification preferences. Their effect (comment
     * notification emails) is applied when comments are posted; the
     * own-comment toggle has no effect while the main one is off.
     */
    public static function updateNotifications(int $id, bool $onComment, bool $onOwnComment): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET notify_on_comment = ?, notify_on_own_comment = ? WHERE id = ?'
        );
        // Bind the booleans explicitly: with native prepares (emulation off),
        // pdo_pgsql sends PHP false as an empty string, which Postgres rejects
        // with "invalid input syntax for type boolean".
        $stmt->bindValue(1, $onComment, PDO::PARAM_BOOL);
        $stmt->bindValue(2, $onOwnComment, PDO::PARAM_BOOL);
        $stmt->bindValue(3, $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
