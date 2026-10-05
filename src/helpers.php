<?php
// Global view helpers, loaded once by bootstrap.php so every view template
// can call them directly.

declare(strict_types=1);

/**
 * Escape a string for safe HTML output. This is the single XSS defence for all
 * user-controlled data rendered in views (usernames, comments, flash messages).
 *
 * @param mixed $value
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
