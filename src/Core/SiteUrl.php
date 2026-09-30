<?php
// SiteUrl: resolves the base URL (scheme://host[:port]) used for links inside
// outgoing emails (verification, later password reset).
//
// Spec section 10 defines APP_URL as the public base URL of the site. For
// local testing from several machines (VM port-forward, laptop on the LAN,
// school computers) APP_URL alone is too rigid, so the base is derived from
// the Host header of the request that triggered the email — but only when
// that host is on an allowlist. The allowlist is the security control that
// prevents host-header poisoning: a forged Host header can never place a
// victim's single-use token into an attacker-controlled link. Anything not
// allowlisted falls back to APP_URL.
//
// The allowlist: the host of APP_URL, plus the comma-separated patterns of
// the APP_ALLOWED_HOSTS environment variable. In a pattern, each "*" covers
// exactly one dot-separated label ("192.168.*.*" matches 192.168.1.20).

declare(strict_types=1);

namespace App\Core;

class SiteUrl
{
    /**
     * Base URL for email links. Uses the request Host when allowed, APP_URL
     * otherwise. The scheme is taken from APP_URL (the site is plain HTTP
     * locally; a future HTTPS deployment only needs APP_URL updated).
     */
    public static function base(Request $request): string
    {
        $appUrl = rtrim(Env::require('APP_URL'), '/');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';

        // A client fully controls the Host header, so accept only the strict
        // shape "hostname[:port]" (letters, digits, dots, hyphens) and reject
        // anything else outright — including any CR/LF injection attempt.
        $host = strtolower((string) $request->header('Host'));
        if (preg_match('/^([a-z0-9.\-]+)(?::(\d{1,5}))?$/', $host, $m) === 1) {
            foreach (self::allowedHosts($appUrl) as $pattern) {
                if (self::matchesPattern($m[1], $pattern)) {
                    return $scheme . '://' . $m[0];
                }
            }
        }

        return $appUrl;
    }

    /**
     * The allowlist: APP_URL's host plus the APP_ALLOWED_HOSTS patterns.
     *
     * @return array<int, string> Lowercase hostname patterns.
     */
    private static function allowedHosts(string $appUrl): array
    {
        $allowed = [];
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $allowed[] = strtolower($appHost);
        }
        foreach (explode(',', (string) Env::get('APP_ALLOWED_HOSTS', '')) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '') {
                $allowed[] = $entry;
            }
        }
        return $allowed;
    }

    /**
     * Match a hostname against an allowlist pattern. Each pattern label must
     * equal the hostname label, or be "*" which covers exactly one label,
     * so "192.168.*.*" cannot match "192.168.1.20.evil.com".
     */
    private static function matchesPattern(string $hostname, string $pattern): bool
    {
        $labels = explode('.', $hostname);
        $patternLabels = explode('.', $pattern);
        if (count($labels) !== count($patternLabels)) {
            return false;
        }
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            if ($patternLabels[$i] !== '*' && $patternLabels[$i] !== $labels[$i]) {
                return false;
            }
        }
        return true;
    }
}
