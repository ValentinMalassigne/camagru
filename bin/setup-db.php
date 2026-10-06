<?php
// bin/setup-db.php — automatic database setup (spec section 10).
//
// Run by docker/php/entrypoint.sh on every container start:
//   1. Wait until the DB accepts connections (bounded retries).
//   2. Check that the required tables exist.
//   3. If any is missing, apply db/schema.sql (idempotent — safe every time).
//   4. Never drop, truncate or overwrite existing data.
//   5. Print nothing on success. On failure, log through PHP error log (the
//      container console) and exit non-zero so the container does not
//      serve a broken site.

declare(strict_types=1);

// Bootstrap gives us the autoloader, app_log() and the error handler.
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;

// Tables the application expects. If any is missing we (re)apply the schema.
const REQUIRED_TABLES = ['users', 'password_resets', 'images', 'likes', 'comments'];

/**
 * Wait for the database to accept a connection. Bounded retries, then fail.
 */
function waitForDatabase(int $maxAttempts = 30, int $delaySeconds = 2): void
{
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            Database::connection();
            return;
        } catch (\Throwable $e) {
            if ($attempt === $maxAttempts) {
                app_log('setup-db: database never became available: ' . $e->getMessage());
                exit(1);
            }
            sleep($delaySeconds);
        }
    }
}

/**
 * Check whether every required table exists in the current database.
 *
 * @return string[] Names of the missing tables.
 */
function missingTables(PDO $pdo): array
{
    $placeholders = implode(', ', array_fill(0, count(REQUIRED_TABLES), '?'));
    $stmt = $pdo->prepare(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename IN ($placeholders)"
    );
    $stmt->execute(REQUIRED_TABLES);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $existing[] = $name;
    }
    return array_diff(REQUIRED_TABLES, $existing);
}

// --- Main ---------------------------------------------------------------

waitForDatabase();

$pdo = Database::connection();

$missing = missingTables($pdo);
if (empty($missing)) {
    // Everything is already in place: do nothing, print nothing.
    return; // exit 0
}

// Apply the idempotent schema. Safe on partial setups and on every start.
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
if ($schema === false) {
    app_log('setup-db: schema.sql could not be read');
    exit(1);
}

try {
    $pdo->exec($schema);
} catch (\Throwable $e) {
    app_log('setup-db: failed to apply schema: ' . $e->getMessage());
    exit(1);
}

// Success: print nothing, exit 0.
