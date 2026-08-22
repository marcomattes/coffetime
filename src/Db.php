<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Datenbankzugriff und Migrationen.
 *
 * Unterstützt zwei Treiber: SQLite (Standard, dateibasiert) und MySQL/MariaDB
 * (über die Option 'db' in der Konfiguration). Alle Aufrufer benutzen
 * ausschliesslich Standard-SQL oder die Helfer dieser Klasse – Dialektfragen
 * bleiben hier gekapselt.
 */
final class Db
{
    /** Zielversion des Schemas. */
    public const SCHEMA_VERSION = 6;

    /** Wartezeit auf eine gesperrte Datenbank. */
    private const BUSY_TIMEOUT_SECONDS = 15;

    /** Wartezeit auf eine InnoDB-Zeilensperre (MySQL/MariaDB), in Sekunden. */
    private const MYSQL_LOCK_WAIT_SECONDS = 15;

    private static ?PDO $pdo = null;

    /** Verbindungsschlüssel (Treiber + Ziel) der zuletzt geöffneten Verbindung. */
    private static ?string $openedPath = null;

    public static function pdo(): PDO
    {
        $config = Config::db();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'sqlite';
        $target = self::targetKey($driver, $config);

        if (self::$pdo !== null && self::$openedPath === $target) {
            return self::$pdo;
        }

        $pdo = $driver === 'mysql' ? self::connectMysql($config) : self::connectSqlite();

        self::$pdo = $pdo;
        self::$openedPath = $target;

        self::migrate($pdo);

        return $pdo;
    }

    /** Aktiver Treiber laut Konfiguration ('sqlite' oder 'mysql'). */
    public static function driver(): string
    {
        return Config::dbDriver();
    }

    /**
     * Baut den Cache-Schlüssel für die geöffnete Verbindung. Ändert sich die
     * Konfiguration (auch der Treiber selbst), muss neu verbunden werden.
     *
     * @param array<string, mixed> $config
     */
    private static function targetKey(string $driver, array $config): string
    {
        if ($driver === 'mysql') {
            return sprintf(
                'mysql|%s:%d/%s',
                (string) ($config['host'] ?? ''),
                (int) ($config['port'] ?? 0),
                (string) ($config['database'] ?? '')
            );
        }

        return 'sqlite|' . Config::dbPath();
    }

    private static function connectSqlite(): PDO
    {
        $path = Config::dbPath();

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

        return $pdo;
    }

    /** @param array<string, mixed> $config */
    private static function connectMysql(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $config['host'],
            (int) $config['port'],
            (string) $config['database'],
            (string) $config['charset']
        );

        $pdo = new PDO($dsn, (string) $config['user'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Die Anwendung berechnet Tagesgrenzen über gmdate() in UTC;
        // FROM_UNIXTIME() muss dieselbe Zeitzone verwenden, sonst verschieben
        // sich Serien- und Verlaufsanzeige um die lokale Differenz.
        $pdo->exec("SET time_zone = '+00:00'");
        // Analog zum SQLite-Busy-Timeout: konkurrierende Schreiber warten auf
        // eine InnoDB-Zeilensperre, statt sofort zu scheitern.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . self::MYSQL_LOCK_WAIT_SECONDS);

        return $pdo;
    }

    /** Treiber der konkret übergebenen Verbindung – unabhängig von Config. */
    private static function driverOf(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
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
     * Portabler Tagesausdruck für einen Unixzeitstempel-Spaltennamen, z. B.
     * für die Serien- und Verlaufsanzeige. Liefert 'YYYY-MM-DD' in UTC.
     */
    public static function dayExpr(string $column): string
    {
        if (Config::dbDriver() === 'mysql') {
            return "DATE_FORMAT(FROM_UNIXTIME({$column}), '%Y-%m-%d')";
        }

        return "date({$column}, 'unixepoch')";
    }

    /**
     * Führt eine Schreiboperation in einer sofort exklusiven Transaktion aus und
     * wiederholt sie, falls die Datenbank kurzzeitig gesperrt meldet (SQLite:
     * "locked"/"busy"; MySQL/MariaDB: Deadlock oder Lock-Wait-Timeout).
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $mysql = self::driverOf($pdo) === 'mysql';
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                if ($mysql) {
                    $pdo->beginTransaction();
                } else {
                    $pdo->exec('BEGIN IMMEDIATE');
                }
            } catch (PDOException $e) {
                if ($attempts < 12 && self::isRetryable($pdo, $e)) {
                    usleep(random_int(2000, 25000));
                    continue;
                }
                throw $e;
            }

            try {
                $result = $work($pdo);
                if ($mysql) {
                    $pdo->commit();
                } else {
                    $pdo->exec('COMMIT');
                }

                return $result;
            } catch (Throwable $e) {
                try {
                    if ($mysql) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    } else {
                        $pdo->exec('ROLLBACK');
                    }
                } catch (Throwable) {
                    // Transaktion war bereits beendet.
                }
                if ($attempts < 12 && $e instanceof PDOException && self::isRetryable($pdo, $e)) {
                    usleep(random_int(2000, 25000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Treiberabhängig: ist der Fehler ein Grund für einen Wiederholungsversuch? */
    private static function isRetryable(PDO $pdo, PDOException $e): bool
    {
        if (self::driverOf($pdo) === 'mysql') {
            $errorInfo = $e->errorInfo ?? null;
            $sqlState = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
            $driverCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;

            // 1213 = Deadlock, 1205 = Lock-Wait-Timeout, 40001 = SQLSTATE für
            // beides, falls errorInfo[1] einmal fehlt.
            return $driverCode === 1213 || $driverCode === 1205 || $sqlState === '40001';
        }

        return self::isBusy($e);
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
     * Liest die angewendete Schemaversion, treiberunabhängig: SQLite über
     * PRAGMA user_version, MySQL/MariaDB über die Tabelle schema_meta.
     */
    private static function readSchemaVersion(PDO $pdo): int
    {
        if (self::driverOf($pdo) !== 'mysql') {
            return self::userVersion($pdo);
        }
        if (!self::tableExists($pdo, 'schema_meta')) {
            return 0;
        }
        $value = self::fetchValue('SELECT version FROM schema_meta WHERE id = 1', [], $pdo);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** Schreibt die angewendete Schemaversion (Gegenstück zu readSchemaVersion). */
    private static function writeSchemaVersion(PDO $pdo, int $version): void
    {
        if (self::driverOf($pdo) !== 'mysql') {
            $pdo->exec('PRAGMA user_version = ' . $version);

            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_meta (
                id TINYINT NOT NULL PRIMARY KEY,
                version INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $statement = $pdo->prepare(
            'INSERT INTO schema_meta (id, version) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version)'
        );
        $statement->execute([$version]);
    }

    /**
     * Migrationsschritte in Reihenfolge. Jeder Schritt ist für sich idempotent,
     * damit ein zweiter Start – oder eine fremde, teilweise vorhandene Datenbank
     * – nichts zerstört und nichts verliert.
     */
    public static function migrate(PDO $pdo): void
    {
        $mysql = self::driverOf($pdo) === 'mysql';
        $steps = $mysql ? self::mysqlSteps() : self::sqliteSteps();

        // Schnellweg: nur lesende Prüfungen. Alles Schreibende darf nicht bei
        // jedem Request laufen.
        $current = self::readSchemaVersion($pdo);
        $complete = self::schemaLooksComplete($pdo);
        if ($current >= self::SCHEMA_VERSION && $complete) {
            return;
        }

        if ($mysql) {
            // MySQL/MariaDB-DDL committet implizit – eine umschliessende
            // Transaktion ist damit wirkungslos und entfällt bewusst. Jeder
            // Schritt ist additiv und idempotent (CREATE TABLE IF NOT EXISTS,
            // ensureColumn), ein Absturz mittendrin ist daher unschädlich:
            // der nächste Start holt die restlichen Schritte nach.
            $from = $complete ? self::readSchemaVersion($pdo) : 0;
            foreach ($steps as $version => $step) {
                if ($version > $from) {
                    $step($pdo);
                }
            }
            self::writeSchemaVersion($pdo, self::SCHEMA_VERSION);
        } else {
            // Exklusive Transaktion, damit parallele erste Requests nicht kollidieren.
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // Behauptet die Datei eine aktuelle Version, das Schema ist aber
                // unvollständig, werden alle Schritte wiederholt. Sie sind
                // idempotent und rein additiv, das kostet nur Zeit, nie Daten.
                $from = $complete ? self::readSchemaVersion($pdo) : 0;
                foreach ($steps as $version => $step) {
                    if ($version > $from) {
                        $step($pdo);
                    }
                }
                self::writeSchemaVersion($pdo, self::SCHEMA_VERSION);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // bereits beendet
                }
                throw $e;
            }
        }

        self::ensureSchema($pdo);
    }

    /** @return array<int, callable(PDO):void> */
    private static function sqliteSteps(): array
    {
        return [
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
            3 => static function (PDO $pdo): void {
                // Ein Ereignis je gebuchtem Kaffee, nur für die Serienanzeige
                // (Tage in Folge). Der coffees-Zähler bleibt die Wahrheit für den
                // Stand; hier steht rein additiv nur, wann gebucht wurde.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS coffee_events (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
            },
            4 => static function (PDO $pdo): void {
                // Nur ein zusammengesetzter Index für den Verlauf (user_id,
                // created_at) – die Tabelle selbst ist bereits vollständig.
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS idx_coffee_events_user_created
                     ON coffee_events (user_id, created_at)'
                );
            },
            5 => static function (PDO $pdo): void {
                // Preis je Buchung: ab jetzt trägt jedes Ereignis seinen
                // eigenen Preis, und der Stand je Nutzer wird additiv über
                // tab_cents geführt statt retroaktiv aus coffees × aktuellem
                // Preis berechnet.
                self::ensureColumn($pdo, 'users', 'tab_cents', 'INTEGER NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'coffee_events', 'price_cents', 'INTEGER NOT NULL DEFAULT 0');
                self::backfillPriceCents($pdo);
            },
            6 => static function (PDO $pdo): void {
                // Admin-Flag direkt am Nutzer: erlaubt eine Admin-Prüfung ohne
                // zusätzlichen Query, sobald die Zeile ohnehin schon geladen ist
                // (siehe Users::isAdminRow()).
                self::ensureColumn($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');
                // Laufzeit-Einstellungen mit Vorrang vor config.php (siehe
                // Config-Klasse): Preis, Einladungscode, öffentlicher
                // Admin-Schlüssel und Namens-Pepper aus dem
                // Einrichtungsassistenten bzw. späteren Admin-Änderungen.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS settings (
                        name TEXT PRIMARY KEY,
                        value TEXT NOT NULL
                    )'
                );
            },
        ];
    }

    /**
     * MySQL/MariaDB-Gegenstück zu sqliteSteps(): gleiche Versionsnummern und
     * gleiche Reihenfolge, aber mit den in der Analyse festgelegten Typen
     * (BIGINT statt INTEGER, VARCHAR für indizierte Zeichenketten, TEXT für
     * grosse Felder).
     *
     * @return array<int, callable(PDO):void>
     */
    private static function mysqlSteps(): array
    {
        return [
            1 => static function (PDO $pdo): void {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS users (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name TEXT,
                        coffees BIGINT NOT NULL DEFAULT 0,
                        paid_cents BIGINT NOT NULL DEFAULT 0,
                        created_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS credentials (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        user_id BIGINT NOT NULL,
                        credential_id VARCHAR(255) NOT NULL,
                        public_key TEXT NOT NULL,
                        sign_count BIGINT NOT NULL DEFAULT 0,
                        created_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS sessions (
                        id VARCHAR(64) PRIMARY KEY,
                        user_id BIGINT NOT NULL,
                        created_at BIGINT NOT NULL DEFAULT 0,
                        expires_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
            2 => static function (PDO $pdo): void {
                self::ensureColumn($pdo, 'users', 'name_encrypted', 'TEXT');
                self::ensureColumn($pdo, 'users', 'name_hash', 'VARCHAR(64)');
                self::ensureColumn($pdo, 'users', 'user_handle', 'VARCHAR(32)');
                self::ensureColumn($pdo, 'credentials', 'aaguid', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'transports', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'attestation_type', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'trust_path', 'TEXT');
                self::ensureColumn($pdo, 'credentials', 'last_used_at', 'BIGINT NOT NULL DEFAULT 0');
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS ceremonies (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        kind VARCHAR(16) NOT NULL,
                        challenge VARCHAR(128) NOT NULL,
                        options TEXT NOT NULL,
                        payload TEXT,
                        used BIGINT NOT NULL DEFAULT 0,
                        created_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
            3 => static function (PDO $pdo): void {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS coffee_events (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        user_id BIGINT NOT NULL,
                        created_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
            4 => static function (PDO $pdo): void {
                // MySQL kennt kein CREATE INDEX IF NOT EXISTS – die Prüfung
                // läuft daher vorab über information_schema.
                if (!self::indexExists($pdo, 'coffee_events', 'idx_coffee_events_user_created')) {
                    $pdo->exec(
                        'CREATE INDEX idx_coffee_events_user_created
                         ON coffee_events (user_id, created_at)'
                    );
                }
            },
            5 => static function (PDO $pdo): void {
                // Preis je Buchung: siehe Kommentar in sqliteSteps().
                self::ensureColumn($pdo, 'users', 'tab_cents', 'BIGINT NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'coffee_events', 'price_cents', 'BIGINT NOT NULL DEFAULT 0');
                self::backfillPriceCents($pdo);
            },
            6 => static function (PDO $pdo): void {
                // Siehe Kommentar in sqliteSteps(): gleiche Semantik, `key`
                // ist in MySQL reserviert – die Spalte heisst daher `name`.
                self::ensureColumn($pdo, 'users', 'is_admin', 'TINYINT NOT NULL DEFAULT 0');
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS settings (
                        name VARCHAR(64) PRIMARY KEY,
                        value TEXT NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
        ];
    }

    /**
     * Backfill für Schema v5: setzt tab_cents und price_cents anhand des zur
     * Migrationszeit konfigurierten Preises. Die Bedingung "= 0" macht den
     * Schritt idempotent – ein zweiter Lauf ändert nichts mehr, sobald einmal
     * befüllt wurde (und trifft auch echte Nutzer ohne Kaffee/Ereignisse nicht,
     * da die WHERE-Klausel zusätzlich coffees > 0 verlangt).
     */
    private static function backfillPriceCents(PDO $pdo): void
    {
        $price = Config::priceCents();

        $users = $pdo->prepare(
            'UPDATE users SET tab_cents = coffees * ? WHERE tab_cents = 0 AND coffees > 0'
        );
        $users->execute([$price]);

        $events = $pdo->prepare('UPDATE coffee_events SET price_cents = ? WHERE price_cents = 0');
        $events->execute([$price]);
    }

    /**
     * Günstige, rein lesende Prüfung, ob das Schema zur Zielversion passt.
     */
    private static function schemaLooksComplete(PDO $pdo): bool
    {
        $userColumns = self::columns($pdo, 'users');
        foreach (['coffees', 'paid_cents', 'name_encrypted', 'name_hash', 'user_handle', 'tab_cents', 'is_admin'] as $column) {
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

        if (!in_array('challenge', self::columns($pdo, 'ceremonies'), true)) {
            return false;
        }

        if (!self::tableExists($pdo, 'coffee_events')) {
            return false;
        }

        return self::tableExists($pdo, 'settings');
    }

    /**
     * Sichert Spalten und Indizes ab, die eine ältere oder fremde Datenbank
     * eventuell nicht mitbringt. Rein additiv – nie DROP, nie CREATE ohne IF NOT
     * EXISTS (bzw. deren mysql-taugliches Äquivalent).
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $mysql = self::driverOf($pdo) === 'mysql';
        $expected = self::expectedColumns($mysql);

        foreach ($expected as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            foreach ($columns as $column => $definition) {
                self::ensureColumn($pdo, $table, $column, $definition);
            }
        }

        if ($mysql) {
            self::ensureMysqlIndexes($pdo);

            return;
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
            'CREATE INDEX IF NOT EXISTS idx_coffee_events_user ON coffee_events (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_coffee_events_user_created ON coffee_events (user_id, created_at)',
        ];
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                error_log('[coffee] index skipped: ' . $e->getMessage());
            }
        }
    }

    /**
     * Dieselben Indizes wie unter SQLite, aber ohne partiellen WHERE-Zusatz:
     * MySQL/MariaDB lässt in einem UNIQUE-Index ohnehin beliebig viele NULLs
     * zu, das deckt denselben Fall ab (Altbestand ohne HMAC/Handle).
     */
    private static function ensureMysqlIndexes(PDO $pdo): void
    {
        $indexes = [
            ['idx_users_name_hash', 'users', 'UNIQUE', '(name_hash)'],
            ['idx_users_handle', 'users', 'UNIQUE', '(user_handle)'],
            ['idx_credentials_credential_id', 'credentials', 'UNIQUE', '(credential_id)'],
            ['idx_credentials_user', 'credentials', '', '(user_id)'],
            ['idx_sessions_user', 'sessions', '', '(user_id)'],
            ['idx_ceremonies_challenge', 'ceremonies', 'UNIQUE', '(challenge)'],
            ['idx_coffee_events_user', 'coffee_events', '', '(user_id)'],
            ['idx_coffee_events_user_created', 'coffee_events', '', '(user_id, created_at)'],
        ];
        foreach ($indexes as [$name, $table, $modifier, $columnsSql]) {
            if (!self::tableExists($pdo, $table) || self::indexExists($pdo, $table, $name)) {
                continue;
            }
            try {
                $pdo->exec(sprintf(
                    'CREATE %sINDEX %s ON %s %s',
                    $modifier !== '' ? $modifier . ' ' : '',
                    self::quoteIdentifier($pdo, $name),
                    self::quoteIdentifier($pdo, $table),
                    $columnsSql
                ));
            } catch (Throwable $e) {
                error_log('[coffee] index skipped: ' . $e->getMessage());
            }
        }
    }

    /**
     * Erwartete Spalten je Tabelle, treiberabhängig nur im Typ. Wird sowohl
     * von ensureSchema() als Sicherheitsnetz benutzt.
     *
     * @return array<string, array<string, string>>
     */
    private static function expectedColumns(bool $mysql): array
    {
        if ($mysql) {
            return [
                'users' => [
                    'name' => 'TEXT',
                    'coffees' => 'BIGINT NOT NULL DEFAULT 0',
                    'paid_cents' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'name_encrypted' => 'TEXT',
                    'name_hash' => 'VARCHAR(64)',
                    'user_handle' => 'VARCHAR(32)',
                    'tab_cents' => 'BIGINT NOT NULL DEFAULT 0',
                    'is_admin' => 'TINYINT NOT NULL DEFAULT 0',
                ],
                'credentials' => [
                    'user_id' => 'BIGINT NOT NULL DEFAULT 0',
                    'credential_id' => 'VARCHAR(255)',
                    'public_key' => 'TEXT',
                    'sign_count' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'aaguid' => 'TEXT',
                    'transports' => 'TEXT',
                    'attestation_type' => 'TEXT',
                    'trust_path' => 'TEXT',
                    'last_used_at' => 'BIGINT NOT NULL DEFAULT 0',
                ],
                'sessions' => [
                    'user_id' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'expires_at' => 'BIGINT NOT NULL DEFAULT 0',
                ],
                'ceremonies' => [
                    'kind' => 'VARCHAR(16)',
                    'challenge' => 'VARCHAR(128)',
                    'options' => 'TEXT',
                    'payload' => 'TEXT',
                    'used' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                ],
                'coffee_events' => [
                    'user_id' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'price_cents' => 'BIGINT NOT NULL DEFAULT 0',
                ],
                'settings' => [
                    'value' => 'TEXT',
                ],
            ];
        }

        return [
            'users' => [
                'name' => 'TEXT',
                'coffees' => 'INTEGER NOT NULL DEFAULT 0',
                'paid_cents' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
                'name_encrypted' => 'TEXT',
                'name_hash' => 'TEXT',
                'user_handle' => 'TEXT',
                'tab_cents' => 'INTEGER NOT NULL DEFAULT 0',
                'is_admin' => 'INTEGER NOT NULL DEFAULT 0',
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
            'coffee_events' => [
                'user_id' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
                'price_cents' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'settings' => [
                'value' => 'TEXT',
            ],
        ];
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        if (self::driverOf($pdo) === 'mysql') {
            return self::fetchRow(
                'SELECT 1 AS found FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
                $pdo
            ) !== null;
        }

        return self::fetchRow(
            "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
            $pdo
        ) !== null;
    }

    /** Existiert ein Index dieses Namens auf dieser Tabelle (nur MySQL/MariaDB)? */
    private static function indexExists(PDO $pdo, string $table, string $indexName): bool
    {
        return self::fetchRow(
            'SELECT 1 AS found FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName],
            $pdo
        ) !== null;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        if (self::driverOf($pdo) === 'mysql') {
            $rows = self::fetchRows(
                'SELECT column_name AS name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
                $pdo
            );
            $names = [];
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $names[] = (string) $row['name'];
                }
            }

            return $names;
        }

        $statement = $pdo->query('PRAGMA table_info(' . self::quoteIdentifier($pdo, $table) . ')');
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
                self::quoteIdentifier($pdo, $table),
                self::quoteIdentifier($pdo, $column),
                $definition
            ));
        } catch (Throwable $e) {
            // Parallel gestartete Instanz war schneller – die Spalte existiert.
            if (!in_array($column, self::columns($pdo, $table), true)) {
                throw $e;
            }
        }
    }

    /** Bezeichner-Quoting ist treiberabhängig: SQLite "..", MySQL `..`. */
    private static function quoteIdentifier(PDO $pdo, string $identifier): string
    {
        if (self::driverOf($pdo) === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
