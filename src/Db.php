<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Datenbankzugriff und Migrationen.
 */
final class Db
{
    /** Zielversion des Schemas. */
    public const SCHEMA_VERSION = 2;

    /** Wartezeit auf eine gesperrte Datenbank. */
    private const BUSY_TIMEOUT_SECONDS = 15;

    private static ?PDO $pdo = null;

    private static ?string $openedPath = null;

    public static function pdo(): PDO
    {
        $path = Config::dbPath();
        if (self::$pdo !== null && self::$openedPath === $path) {
            return self::$pdo;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create database directory');
            }
            // Sicherheitsnetz für Hosting, bei dem das Verzeichnis im
            // Dokumentenbaum landet. Ohne Apache schadet die Datei nicht.
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => self::BUSY_TIMEOUT_SECONDS,
        ]);
        // Das Busy-Timeout lässt konkurrierende Schreiber warten statt zu
        // scheitern. WAL erlaubt Leser neben einem Schreiber; der Modus steckt
        // in der Datei, deshalb wird er nur umgestellt, wenn er noch fehlt –
        // ein Umschalten braucht eine exklusive Sperre.
        $pdo->exec('PRAGMA busy_timeout = ' . (self::BUSY_TIMEOUT_SECONDS * 1000));
        try {
            $mode = $pdo->query('PRAGMA journal_mode')->fetchAll();
            $current = strtolower((string) ($mode[0]['journal_mode'] ?? ''));
            if ($current !== 'wal') {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
        } catch (Throwable $e) {
            error_log('[coffee] WAL not enabled: ' . $e->getMessage());
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::$openedPath = $path;

        self::migrate($pdo);

        return $pdo;
    }

    /**
     * Liest genau eine Zeile und gibt den Cursor sofort frei.
     *
     * Ein offener Cursor hält in WAL-Modus eine Lesetransaktion. Ein
     * anschliessendes BEGIN IMMEDIATE würde dann mit SQLITE_BUSY_SNAPSHOT
     * scheitern, ohne dass das Busy-Timeout greift.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchRow(string $sql, array $params = [], ?PDO $pdo = null): ?array
    {
        $pdo ??= self::pdo();
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        $statement->closeCursor();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function fetchRows(string $sql, array $params = [], ?PDO $pdo = null): array
    {
        $pdo ??= self::pdo();
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        return array_values(is_array($rows) ? $rows : []);
    }

    /** @param list<mixed> $params */
    public static function fetchValue(string $sql, array $params = [], ?PDO $pdo = null): mixed
    {
        $row = self::fetchRow($sql, $params, $pdo);
        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$openedPath = null;
    }

    /**
     * Führt eine Schreiboperation in einer sofort exklusiven Transaktion aus und
     * wiederholt sie, falls SQLite die Datenbank kurzzeitig gesperrt meldet.
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                $pdo->exec('BEGIN IMMEDIATE');
            } catch (PDOException $e) {
                if ($attempts < 12 && self::isBusy($e)) {
                    usleep(random_int(2000, 25000));
                    continue;
                }
                throw $e;
            }

            try {
                $result = $work($pdo);
                $pdo->exec('COMMIT');

                return $result;
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Transaktion war bereits beendet.
                }
                if ($attempts < 12 && $e instanceof PDOException && self::isBusy($e)) {
                    usleep(random_int(2000, 25000));
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function isBusy(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'locked') || str_contains($message, 'busy');
    }

    public static function userVersion(PDO $pdo): int
    {
        $rows = $pdo->query('PRAGMA user_version')->fetchAll();
        $value = $rows[0]['user_version'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Migrationsschritte in Reihenfolge. Jeder Schritt ist für sich idempotent,
     * damit ein zweiter Start – oder eine fremde, teilweise vorhandene Datenbank
     * – nichts zerstört und nichts verliert.
     */
    public static function migrate(PDO $pdo): void
    {
        $steps = [
            1 => static function (PDO $pdo): void {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS users (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT,
                        coffees INTEGER NOT NULL DEFAULT 0,
                        paid_cents INTEGER NOT NULL DEFAULT 0,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS credentials (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        credential_id TEXT NOT NULL,
                        public_key TEXT NOT NULL,
                        sign_count INTEGER NOT NULL DEFAULT 0,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS sessions (
                        id TEXT PRIMARY KEY,
                        user_id INTEGER NOT NULL,
                        created_at INTEGER NOT NULL DEFAULT 0,
                        expires_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
            },
            2 => static function (PDO $pdo): void {
                // Verschlüsselter Name, Eindeutigkeits-HMAC und WebAuthn-Handle.
                self::ensureColumn($pdo, 'users', 'name_encrypted', 'TEXT');
                self::ensureColumn($pdo, 'users', 'name_hash', 'TEXT');
                self::ensureColumn($pdo, 'users', 'user_handle', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'aaguid', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'transports', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'attestation_type', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'trust_path', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'last_used_at', 'INTEGER NOT NULL DEFAULT 0');
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS ceremonies (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        kind TEXT NOT NULL,
                        challenge TEXT NOT NULL,
                        options TEXT NOT NULL,
                        payload TEXT,
                        used INTEGER NOT NULL DEFAULT 0,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
            },
        ];

        // Schnellweg: nur lesende Prüfungen. Alles Schreibende darf nicht bei
        // jedem Request laufen.
        $current = self::userVersion($pdo);
        $complete = self::schemaLooksComplete($pdo);
        if ($current >= self::SCHEMA_VERSION && $complete) {
            return;
        }

        // Exklusive Transaktion, damit parallele erste Requests nicht kollidieren.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Behauptet die Datei eine aktuelle Version, das Schema ist aber
            // unvollständig, werden alle Schritte wiederholt. Sie sind
            // idempotent und rein additiv, das kostet nur Zeit, nie Daten.
            $from = $complete ? self::userVersion($pdo) : 0;
            foreach ($steps as $version => $step) {
                if ($version > $from) {
                    $step($pdo);
                }
            }
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // bereits beendet
            }
            throw $e;
        }

        self::ensureSchema($pdo);
    }

    /**
     * Günstige, rein lesende Prüfung, ob das Schema zur Zielversion passt.
     */
    private static function schemaLooksComplete(PDO $pdo): bool
    {
        $userColumns = self::columns($pdo, 'users');
        foreach (['coffees', 'paid_cents', 'name_encrypted', 'name_hash', 'user_handle'] as $column) {
            if (!in_array($column, $userColumns, true)) {
                return false;
            }
        }
        if (!in_array('sign_count', self::columns($pdo, 'credentials'), true)) {
            return false;
        }
        if (!in_array('expires_at', self::columns($pdo, 'sessions'), true)) {
            return false;
        }

        return in_array('challenge', self::columns($pdo, 'ceremonies'), true);
    }

    /**
     * Sichert Spalten und Indizes ab, die eine ältere oder fremde Datenbank
     * eventuell nicht mitbringt. Rein additiv – nie DROP, nie CREATE ohne IF NOT
     * EXISTS.
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $expected = [
            'users' => [
                'name' => 'TEXT',
                'coffees' => 'INTEGER NOT NULL DEFAULT 0',
                'paid_cents' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
                'name_encrypted' => 'TEXT',
                'name_hash' => 'TEXT',
                'user_handle' => 'TEXT',
            ],
            'credentials' => [
                'user_id' => 'INTEGER NOT NULL DEFAULT 0',
                'credential_id' => 'TEXT',
                'public_key' => 'TEXT',
                'sign_count' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
                'aaguid' => 'TEXT',
                'transports' => 'TEXT',
                'attestation_type' => 'TEXT',
                'trust_path' => 'TEXT',
                'last_used_at' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'sessions' => [
                'user_id' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
                'expires_at' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'ceremonies' => [
                'kind' => 'TEXT',
                'challenge' => 'TEXT',
                'options' => 'TEXT',
                'payload' => 'TEXT',
                'used' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
            ],
        ];

        foreach ($expected as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            foreach ($columns as $column => $definition) {
                self::ensureColumn($pdo, $table, $column, $definition);
            }
        }

        // Eindeutigkeit; die partiellen Indizes lassen Altbestand ohne HMAC zu.
        // Sollte ein Index an Altdaten scheitern, bleibt die Anwendung lauffähig.
        $indexes = [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name_hash ON users (name_hash) WHERE name_hash IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_handle ON users (user_handle) WHERE user_handle IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_credentials_credential_id ON credentials (credential_id)',
            'CREATE INDEX IF NOT EXISTS idx_credentials_user ON credentials (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_ceremonies_challenge ON ceremonies (challenge)',
        ];
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                error_log('[coffee] index skipped: ' . $e->getMessage());
            }
        }
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        return self::fetchRow(
            "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
            $pdo
        ) !== null;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('PRAGMA table_info(' . self::quoteIdentifier($table) . ')');
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $names = [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }

        return $names;
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::tableExists($pdo, $table)) {
            return;
        }
        if (in_array($column, self::columns($pdo, $table), true)) {
            return;
        }
        try {
            $pdo->exec(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s',
                self::quoteIdentifier($table),
                self::quoteIdentifier($column),
                $definition
            ));
        } catch (Throwable $e) {
            // Parallel gestartete Instanz war schneller – die Spalte existiert.
            if (!in_array($column, self::columns($pdo, $table), true)) {
                throw $e;
            }
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
