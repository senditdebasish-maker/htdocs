<?php
/**
 * Database access layer — self-healing.
 *
 * A thin PDO wrapper with helpers for parameterised queries, transactions and
 * dialect-aware table creation. Supports MySQL (XAMPP) and SQLite (tests/dev).
 *
 * Self-healing behaviour:
 *   • MySQL: if the target database does not exist yet, it is created on the
 *     first connection attempt (no manual phpMyAdmin step required).
 *   • A lost server connection ("server has gone away", timeouts, restarts) is
 *     detected and the connection is transparently re-established once.
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

    /** Build a PDO handle with the project's standard options. */
    private static function makePdo(string $dsn): PDO
    {
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public static function connect(): void
    {
        if (DB_DRIVER === 'sqlite') {
            if (DB_SQLITE_FILE !== ':memory:' && !is_dir(dirname(DB_SQLITE_FILE))) {
                @mkdir(dirname(DB_SQLITE_FILE), 0775, true);
            }
            self::$pdo = self::makePdo('sqlite:' . DB_SQLITE_FILE);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$driver = 'sqlite';
            return;
        }

        $base = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
        $withDb = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

        try {
            self::$pdo = self::makePdo($withDb);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code !== 1049) { // 1049 = unknown database
                throw $e;
            }
            // Database missing — create it and reconnect (self-heal).
            $tmp = self::makePdo($base);
            $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . addcslashes(DB_NAME, '`') . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $tmp = null;
            self::$pdo = self::makePdo($withDb);
        }
        self::$driver = 'mysql';
    }

    /** Drop the cached connection so the next query reconnects. */
    public static function reconnect(): void
    {
        self::$pdo = null;
        self::connect();
    }

    /**
     * Run a query callback, transparently reconnecting once if the connection
     * was lost (2006 gone away / 2013 lost during query / 1927 killed).
     */
    private static function q(callable $fn)
    {
        try {
            return $fn(self::pdo());
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 2006 || $code === 2013 || $code === 1927) {
                self::$pdo = null;
                self::connect();
                return $fn(self::pdo());
            }
            throw $e;
        }
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::q(function (PDO $pdo) use ($sql, $params) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll();
        });
    }

    /** Fetch a single row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        return self::q(function (PDO $pdo) use ($sql, $params) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch();
            return $row === false ? null : $row;
        });
    }

    /** Fetch a single scalar value or null. */
    public static function val(string $sql, array $params = [])
    {
        return self::q(function (PDO $pdo) use ($sql, $params) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return $v === false ? null : $v;
        });
    }

    /** Execute a write statement; returns affected row count. */
    public static function run(string $sql, array $params = []): int
    {
        return self::q(function (PDO $pdo) use ($sql, $params) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->rowCount();
        });
    }

    /** Execute an INSERT; returns the new auto-increment id. */
    public static function insert(string $sql, array $params = []): int
    {
        return self::q(function (PDO $pdo) use ($sql, $params) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (int) $pdo->lastInsertId();
        });
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
