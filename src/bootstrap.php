<?php
// Bootstrap: autoloader, error/exception handling, session.
// Included by the front controller and by CLI scripts (setup-db).

declare(strict_types=1);

// --- Autoloader ---------------------------------------------------------
// PSR-4-style: App\ maps to src/. No Composer.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Global view helpers (e(), e_attr()) used by every template.
require __DIR__ . '/helpers.php';

// Application root (the project directory), used to reach config/ and the
// bundled overlay assets from anywhere in src/.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// --- Log destination ----------------------------------------------------
// App log file: overridable via APP_LOG_FILE, defaults to the docker volume.
if (!defined('APP_LOG_FILE')) {
    define('APP_LOG_FILE', getenv('APP_LOG_FILE') ?: '/var/log/camagru/app.log');
}

// Uploaded composited pictures (spec section 8): written by php, served
// read-only by nginx from the same shared volume. Overridable via
// APP_UPLOAD_DIR, defaults to the docker volume.
if (!defined('APP_UPLOAD_DIR')) {
    define('APP_UPLOAD_DIR', getenv('APP_UPLOAD_DIR') ?: APP_ROOT . '/uploads');
}

/**
 * Write a single line to the app log file. Never prints to the console.
 * Used by the error handler and by feature code that needs deliberate logging.
 */
function app_log(string $message): void
{
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    // Suppress errors: logging must never break the request.
    @file_put_contents(APP_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Format a throwable as a single log line (no stack trace leaking to the user).
 */
function app_log_throwable(Throwable $e): void
{
    app_log(sprintf(
        '%s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

// --- Error and exception handling --------------------------------------
// Everything is logged to a file; the user only ever sees a generic 500 page.

set_exception_handler(function (Throwable $e): void {
    app_log_throwable($e);
    // If headers are not sent, emit a generic 500. In CLI, just exit non-zero.
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        // Generic message, no internal detail.
        echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\">";
        echo "<title>Server error</title></head><body>";
        echo "<h1>Something went wrong</h1>";
        echo "<p>Please try again later.</p>";
        echo "</body></html>";
    }
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // Respect the current error_reporting level (@-suppressed errors stay quiet).
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Convert to an ErrorException so the exception handler logs it uniformly.
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors (E_ERROR, E_PARSE, ...) that set_error_handler cannot.
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    app_log(sprintf('FATAL %s: %s in %s:%d', $error['type'], $error['message'], $error['file'], $error['line']));
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }
});

// --- Session ------------------------------------------------------------
// Started only for web (HTTP) requests, not for the CLI setup script.
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Secure cookie settings (see spec section 9). Secure flag is off for HTTP.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // localhost is HTTP; set to true when serving HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('camagru_sid');
    session_start();
}
