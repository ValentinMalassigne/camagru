<?php
// Thin wrapper around $_SESSION with flash-message support. Session start and
// secure cookie settings are handled in bootstrap.php.

declare(strict_types=1);

namespace App\Core;

class Session
{
    private const FLASH_KEY = '_flashes';

    /**
     * Get a session value, or a default.
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Store a value in the session.
     *
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Remove a key from the session.
     */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Set a flash message of the given type ("success", "error", "info").
     * Flash messages are shown once on the next request and escaped on output.
     */
    public static function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Pull all flash messages and clear them. Returns an array of
     * ['type' => ..., 'message' => ...] entries.
     *
     * @return array<int, array{type: string, message: string}>
     */
    public static function pullFlashes(): array
    {
        $flashes = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);
        return $flashes;
    }

    /**
     * Regenerate the session ID (used on login to prevent fixation).
     * Deletes the old session file.
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Fully destroy the session (used on logout).
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
