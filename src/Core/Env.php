<?php
// Reads environment variables from the process environment (set by docker
// from the git-ignored .env). Never invents values.

declare(strict_types=1);

namespace App\Core;

class Env
{
    /**
     * Get an environment variable, returning a default if unset.
     */
    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * Get a required environment variable; throws if missing.
     */
    public static function require(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Missing required environment variable: $name");
        }
        return $value;
    }
}
