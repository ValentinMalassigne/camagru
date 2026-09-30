<?php
// CSRF token generation and verification. One per-session token, generated with
// random_bytes and checked with hash_equals (timing-safe). Required on every
// POST (form field or X-CSRF-Token header for fetch).
//
// This exists to prevent forged cross-site requests on private state-changing
// actions (logout, delete, like, comment, etc.).

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    private const TOKEN_KEY = '_csrf_token';

    /**
     * Get the current token, generating one if missing.
     */
    public static function token(): string
    {
        $token = Session::get(self::TOKEN_KEY);
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::TOKEN_KEY, $token);
        }
        return $token;
    }

    /**
     * Verify a submitted token against the session token. Timing-safe.
     */
    public static function check(string $submitted): bool
    {
        $token = Session::get(self::TOKEN_KEY);
        if ($token === null || $submitted === '') {
            return false;
        }
        return hash_equals((string) $token, $submitted);
    }

    /**
     * Read the token from the request (POST field or X-CSRF-Token header),
     * verify it, and return true if valid.
     */
    public static function verify(Request $request): bool
    {
        $submitted = $request->post('_csrf_token');
        if ($submitted === null) {
            $submitted = $request->header('X-CSRF-Token');
        }
        return self::check((string) $submitted);
    }
}
