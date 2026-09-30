<?php
// Database: a single PDO connection to PostgreSQL, configured per spec section 7.
// Every query elsewhere must use bound parameters; this class only provides the
// connection and the PDO settings that enforce safe defaults.

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    /**
     * Return the shared PDO connection, creating it from env vars on first use.
     * Throws a RuntimeException with a generic message on failure (logged too).
     */
    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Env::require('DB_HOST');
        $port = Env::get('DB_PORT', '5432');
        $name = Env::require('DB_NAME');
        $user = Env::require('DB_USER');
        $pass = Env::require('DB_PASSWORD');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // Log the real error, surface only a generic message.
            app_log('Database connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database is unavailable.');
        }

        return self::$pdo;
    }
}
