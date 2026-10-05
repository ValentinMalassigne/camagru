<?php
// Auth: session-backed authentication state. The current user is identified
// by the user id stored in the session; this class loads the matching row
// once per request and exposes it to the views (and later to controllers
// guarding private routes).
//
// Security notes:
// - session_regenerate_id(true) on login (spec section 9) prevents session
//   fixation: the pre-login id becomes worthless.
// - The session is the only credential store; logout removes it, and the
//   full session destroy on logout invalidates everything else stored there.

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

class Auth
{
    private const USER_KEY = 'user_id';

    /** @var array<string, mixed>|null Cached user row for this request. */
    private static ?array $user = null;
    /** @var bool Whether the user row was already looked up in this request. */
    private static bool $loaded = false;

    /**
     * Log a user in: rotate the session id (anti-fixation), remember the id.
     *
     * @param array<string, mixed> $user Row from the users table.
     */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set(self::USER_KEY, (int) $user['id']);
        self::$user = $user;
        self::$loaded = true;
    }

    /**
     * Log the current user out of this request only (the controller then
     * destroys the whole session).
     */
    public static function logout(): void
    {
        Session::forget(self::USER_KEY);
        self::$user = null;
        self::$loaded = true;
    }

    /**
     * The logged-in user's row, or null. Loads once per request from the
     * session's user id. An id that no longer matches a verified account is
     * dropped from the session (deleted or unverified users are treated as
     * logged out).
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$user = null;
            $id = Session::get(self::USER_KEY);
            if ($id !== null) {
                $user = User::findById((int) $id);
                if ($user !== null && (bool) $user['is_verified']) {
                    self::$user = $user;
                } else {
                    Session::forget(self::USER_KEY);
                }
            }
            self::$loaded = true;
        }
        return self::$user;
    }
}
