<?php
/**
 * Database access layer.
 *
 * A thin PDO wrapper with helpers for parameterised queries, transactions and
 * dialect-aware table creation. Supports MySQL (XAMPP) and SQLite (tests/dev).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class DB
{
    /** @var PDO|null */
    private static $pdo = null;
    private static string $driver = 'mysql';

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }

    public static function connect(): void
    {
        if (DB_DRIVER === 'sqlite') {
            if (DB_SQLITE_FILE !== ':memory:' && !is_dir(dirname(DB_SQLITE_FILE))) {
                @mkdir(dirname(DB_SQLITE_FILE), 0775, true);
            }
            self::$pdo = new PDO('sqlite:' . DB_SQLITE_FILE, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$driver = 'sqlite';
            return;
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$driver = 'mysql';
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Fetch a single row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch a single scalar value or null. */
    public static function val(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Execute a write statement; returns affected row count. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Execute an INSERT; returns the new auto-increment id. */
    public static function insert(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Run $fn inside a transaction; rolls back on exception. */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True if the underlying database is SQLite. */
    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }
}
