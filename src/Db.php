<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Database access and migrations.
 *
 * Supports two drivers: SQLite (default, file-based) and MySQL/MariaDB (via the
 * 'db' option in the configuration). All callers use plain standard SQL or the
 * helpers of this class – questions of dialect stay encapsulated here.
 */
final class Db
{
    /** Target version of the schema. */
    public const SCHEMA_VERSION = 11;

    /** Time to wait for a locked database. */
    private const BUSY_TIMEOUT_SECONDS = 15;

    /** Time to wait for an InnoDB row lock (MySQL/MariaDB), in seconds. */
    private const MYSQL_LOCK_WAIT_SECONDS = 15;

    private static ?PDO $pdo = null;

    /** Connection key (driver + target) of the most recently opened connection. */
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

    /** Active driver according to the configuration ('sqlite' or 'mysql'). */
    public static function driver(): string
    {
        return Config::dbDriver();
    }

    /**
     * Builds the cache key for the open connection. If the configuration
     * changes (the driver itself included), a new connection is required.
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
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create database directory');
        }
        // Safety net for hosting where the directory ends up inside the
        // document tree. Written whenever it is missing, not only when this
        // call created the directory: an FTP client or a restored backup can
        // put data/ in place without it, and this directory holds both the
        // database and the first-run setup token. Without Apache the file does
        // no harm -- and on nginx it does nothing, so the document root still
        // has to point at public/.
        if (!is_file($dir . '/.htaccess')) {
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
        // The busy timeout makes competing writers wait instead of fail. WAL
        // allows readers alongside a writer; the mode is stored in the file, so
        // it is only switched when still missing – switching requires an
        // exclusive lock.
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
        // The application computes day boundaries with gmdate() in UTC;
        // FROM_UNIXTIME() has to use the same time zone, otherwise streak and
        // history display shift by the local offset.
        $pdo->exec("SET time_zone = '+00:00'");
        // Analogous to the SQLite busy timeout: competing writers wait for an
        // InnoDB row lock instead of failing immediately.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . self::MYSQL_LOCK_WAIT_SECONDS);

        return $pdo;
    }

    /** Driver of the connection actually passed in – independent of Config. */
    private static function driverOf(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    }

    /**
     * Reads exactly one row and releases the cursor immediately.
     *
     * In WAL mode an open cursor holds a read transaction. A subsequent
     * BEGIN IMMEDIATE would then fail with SQLITE_BUSY_SNAPSHOT, without the
     * busy timeout taking effect.
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
     * Portable day expression for a column holding a Unix timestamp, e.g. for
     * the streak and history display. Returns 'YYYY-MM-DD' in UTC.
     */
    public static function dayExpr(string $column): string
    {
        // Cast to int so the offset can never carry anything but a number
        // into the statement; it comes from configuration, not from a request.
        $offset = Config::dayOffsetSeconds();
        $shifted = $offset === 0 ? $column : '(' . $column . ' + ' . $offset . ')';

        if (Config::dbDriver() === 'mysql') {
            return "DATE_FORMAT(FROM_UNIXTIME({$shifted}), '%Y-%m-%d')";
        }

        return "date({$shifted}, 'unixepoch')";
    }

    /**
     * Runs a write operation in an immediately exclusive transaction and
     * repeats it if the database reports a transient lock (SQLite:
     * "locked"/"busy"; MySQL/MariaDB: deadlock or lock wait timeout).
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
                    // Transaction had already ended.
                }
                if ($attempts < 12 && $e instanceof PDOException && self::isRetryable($pdo, $e)) {
                    usleep(random_int(2000, 25000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Driver-dependent: is the error a reason to retry? */
    private static function isRetryable(PDO $pdo, PDOException $e): bool
    {
        if (self::driverOf($pdo) === 'mysql') {
            $errorInfo = $e->errorInfo ?? null;
            $sqlState = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
            $driverCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;

            // 1213 = deadlock, 1205 = lock wait timeout, 40001 = SQLSTATE for
            // both, should errorInfo[1] ever be missing.
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
     * Reads the applied schema version, driver-independently: SQLite via
     * PRAGMA user_version, MySQL/MariaDB via the schema_meta table.
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

    /** Writes the applied schema version (counterpart to readSchemaVersion). */
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
     * Migration steps in order. Each step is idempotent on its own, so that a
     * second start – or a foreign, partially present database – destroys
     * nothing and loses nothing.
     */
    public static function migrate(PDO $pdo): void
    {
        $mysql = self::driverOf($pdo) === 'mysql';
        $steps = $mysql ? self::mysqlSteps() : self::sqliteSteps();

        // Fast path: read-only checks. Nothing that writes may run on every
        // request.
        $current = self::readSchemaVersion($pdo);
        $complete = self::schemaLooksComplete($pdo);
        if ($current >= self::SCHEMA_VERSION && $complete) {
            // Index creation is allowed to fail (legacy data can violate a
            // uniqueness constraint) and is only logged. Without this check a
            // once-failed unique index would never be attempted again, so
            // name/credential uniqueness could stay silently unenforced.
            if (!self::criticalIndexesPresent($pdo)) {
                self::ensureSchema($pdo);
            }

            return;
        }

        if ($mysql) {
            // MySQL DDL cannot run inside a transaction, so concurrent cold
            // starts would otherwise race each other through the steps. An
            // advisory lock serializes them; if it cannot be taken the
            // migration still proceeds, since every step is idempotent.
            $locked = self::acquireMysqlLock($pdo);
            try {
                self::runSteps($pdo, $steps, $complete);
            } finally {
                if ($locked) {
                    self::releaseMysqlLock($pdo);
                }
            }
            self::ensureSchema($pdo);

            return;
        }

        // Exclusive transaction so that concurrent first requests do not collide.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            self::runSteps($pdo, $steps, $complete);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // already ended
            }
            throw $e;
        }

        self::ensureSchema($pdo);
    }

    /**
     * Applies every step newer than the recorded version and stamps the new
     * one. If the schema is incomplete the version is ignored and all steps
     * are repeated: they are idempotent and purely additive, which costs time
     * only, never data.
     *
     * @param array<int, callable(PDO):void> $steps
     */
    private static function runSteps(PDO $pdo, array $steps, bool $complete): void
    {
        $from = $complete ? self::readSchemaVersion($pdo) : 0;
        foreach ($steps as $version => $step) {
            if ($version > $from) {
                $step($pdo);
            }
        }
        self::writeSchemaVersion($pdo, self::SCHEMA_VERSION);
    }

    /** Name of the advisory lock that serializes MySQL/MariaDB migrations. */
    private const MYSQL_MIGRATION_LOCK = 'coffee_migrate';

    private static function acquireMysqlLock(PDO $pdo): bool
    {
        try {
            $value = self::fetchValue(
                'SELECT GET_LOCK(?, ?) AS got',
                [self::MYSQL_MIGRATION_LOCK, self::MYSQL_LOCK_WAIT_SECONDS],
                $pdo
            );

            return is_numeric($value) && (int) $value === 1;
        } catch (Throwable $e) {
            error_log('[coffee] migration lock unavailable: ' . $e->getMessage());

            return false;
        }
    }

    private static function releaseMysqlLock(PDO $pdo): void
    {
        try {
            self::fetchValue('SELECT RELEASE_LOCK(?) AS released', [self::MYSQL_MIGRATION_LOCK], $pdo);
        } catch (Throwable $e) {
            error_log('[coffee] migration lock release failed: ' . $e->getMessage());
        }
    }

    /**
     * Cheap check that the uniqueness-critical indexes really exist. They are
     * created best-effort (a failure is logged, not fatal), so this is what
     * makes a failed attempt get retried instead of silently standing.
     */
    private static function criticalIndexesPresent(PDO $pdo): bool
    {
        $required = [
            'idx_users_name_hash',
            'idx_users_handle',
            'idx_credentials_credential_id',
            'idx_ceremonies_challenge',
            'idx_link_codes_hash',
            'idx_coffee_events_user_client',
        ];

        if (self::driverOf($pdo) === 'mysql') {
            foreach ($required as $name) {
                $table = $name === 'idx_users_name_hash' || $name === 'idx_users_handle'
                    ? 'users'
                    : ($name === 'idx_credentials_credential_id'
                        ? 'credentials'
                        : ($name === 'idx_ceremonies_challenge'
                            ? 'ceremonies'
                            : ($name === 'idx_link_codes_hash' ? 'link_codes' : 'coffee_events')));
                if (!self::indexExists($pdo, $table, $name)) {
                    return false;
                }
            }

            return true;
        }

        $rows = self::fetchRows("SELECT name FROM sqlite_master WHERE type = 'index'", [], $pdo);
        $present = [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $present[(string) $row['name']] = true;
            }
        }
        foreach ($required as $name) {
            if (!isset($present[$name])) {
                return false;
            }
        }

        return true;
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
                // Encrypted name, uniqueness HMAC and WebAuthn handle.
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
                // One event per booked coffee, only for the streak display
                // (consecutive days). The coffees counter remains the truth for
                // the balance; this table records, purely additively, only when
                // a booking happened.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS coffee_events (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
            },
            4 => static function (PDO $pdo): void {
                // Only a composite index for the history (user_id, created_at)
                // – the table itself is already complete.
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS idx_coffee_events_user_created
                     ON coffee_events (user_id, created_at)'
                );
            },
            5 => static function (PDO $pdo): void {
                // Price per booking: from here on every event carries its own
                // price, and the per-user balance is kept additively in
                // tab_cents instead of being computed retroactively from
                // coffees × current price.
                self::ensureColumn($pdo, 'users', 'tab_cents', 'INTEGER NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'coffee_events', 'price_cents', 'INTEGER NOT NULL DEFAULT 0');
                self::backfillPriceCents($pdo);
            },
            6 => static function (PDO $pdo): void {
                // Admin flag directly on the user: allows an admin check
                // without an extra query once the row is loaded anyway (see
                // Users::isAdminRow()).
                self::ensureColumn($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');
                // Runtime settings that take precedence over config.php (see
                // the Config class): price, invite code, public admin key and
                // name pepper, coming from the setup wizard or from later admin
                // changes.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS settings (
                        name TEXT PRIMARY KEY,
                        value TEXT NOT NULL
                    )'
                );
            },
            7 => static function (PDO $pdo): void {
                // Single-use codes for linking a second device to an existing
                // account: self-issued ("self") or issued by an admin on device
                // loss ("admin"). As with ceremonies, only the SHA-256 hash is
                // stored, never the cleartext code.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS link_codes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        code_hash TEXT NOT NULL,
                        created_by TEXT NOT NULL,
                        used INTEGER NOT NULL DEFAULT 0,
                        expires_at INTEGER NOT NULL DEFAULT 0,
                        created_at INTEGER NOT NULL DEFAULT 0
                    )'
                );
            },
            8 => static function (PDO $pdo): void {
                // Idempotency key for the offline booking queue: a client
                // assigns its own event ID before sending and may repeat a
                // booking arbitrarily often (poor Wi-Fi in the kitchen) without
                // booking twice. NULL for older clients without an event ID;
                // the partial unique index permits any number of such NULLs.
                self::ensureColumn($pdo, 'coffee_events', 'client_event_id', 'TEXT');
                $pdo->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS idx_coffee_events_client
                     ON coffee_events (client_event_id) WHERE client_event_id IS NOT NULL'
                );
            },
            9 => static function (PDO $pdo): void {
                // Local payment reminders without a push server: the client
                // polls /api/reminders and shows local notifications.
                // remind_requested_at carries an outstanding admin reminder
                // (0 = none), reminded_month the most recently acknowledged
                // month-end notice ('YYYY-MM'), so that every reminder appears
                // at most once across all of a user's devices.
                self::ensureColumn($pdo, 'users', 'remind_requested_at', 'INTEGER NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'users', 'reminded_month', 'TEXT');
            },
            10 => static function (PDO $pdo): void {
                // Fixed-window counters for the unauthenticated endpoints
                // (see the RateLimit class).
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS rate_limits (
                        bucket TEXT PRIMARY KEY,
                        attempts INTEGER NOT NULL DEFAULT 0,
                        window_start INTEGER NOT NULL DEFAULT 0
                    )'
                );
                // The offline-queue idempotency key is only unique *per user*.
                // As a global key, one account could take an id another
                // account later generated, and that second booking would be
                // swallowed with a success response.
                self::dropIndex($pdo, 'coffee_events', 'idx_coffee_events_client');
                $pdo->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS idx_coffee_events_user_client
                     ON coffee_events (user_id, client_event_id) WHERE client_event_id IS NOT NULL'
                );
            },
            11 => static function (PDO $pdo): void {
                // Optional password login for administrators (see Passwords).
                // NULL means "no password set", which is the state every
                // existing row keeps: the column only ever becomes non-NULL
                // when an admin deliberately sets one.
                self::ensureColumn($pdo, 'users', 'password_hash', 'TEXT');
                self::ensureColumn($pdo, 'users', 'password_set_at', 'INTEGER NOT NULL DEFAULT 0');
            },
        ];
    }

    /**
     * MySQL/MariaDB counterpart to sqliteSteps(): same version numbers and same
     * order, but with the types this driver requires (BIGINT instead of
     * INTEGER, VARCHAR for indexed strings, TEXT for large fields).
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
                // MySQL has no CREATE INDEX IF NOT EXISTS – the check therefore
                // runs up front via information_schema.
                if (!self::indexExists($pdo, 'coffee_events', 'idx_coffee_events_user_created')) {
                    // indexExists-then-CREATE is not atomic: two cold starts
                    // can both pass the check, and the loser must not 500.
                    try {
                        $pdo->exec(
                            'CREATE INDEX idx_coffee_events_user_created
                             ON coffee_events (user_id, created_at)'
                        );
                    } catch (Throwable $e) {
                        error_log('[coffee] index skipped: ' . $e->getMessage());
                    }
                }
            },
            5 => static function (PDO $pdo): void {
                // Price per booking: see the comment in sqliteSteps().
                self::ensureColumn($pdo, 'users', 'tab_cents', 'BIGINT NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'coffee_events', 'price_cents', 'BIGINT NOT NULL DEFAULT 0');
                self::backfillPriceCents($pdo);
            },
            6 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps(): same semantics; `key` is
                // reserved in MySQL, so the column is named `name`.
                self::ensureColumn($pdo, 'users', 'is_admin', 'TINYINT NOT NULL DEFAULT 0');
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS settings (
                        name VARCHAR(64) PRIMARY KEY,
                        value TEXT NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
            7 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps().
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS link_codes (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        user_id BIGINT NOT NULL,
                        code_hash VARCHAR(64) NOT NULL,
                        created_by VARCHAR(16) NOT NULL,
                        used BIGINT NOT NULL DEFAULT 0,
                        expires_at BIGINT NOT NULL DEFAULT 0,
                        created_at BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            },
            8 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps(). MySQL/MariaDB permits any
                // number of NULLs in an ordinary UNIQUE index – a partial index
                // (WHERE ...) is not needed here.
                self::ensureColumn($pdo, 'coffee_events', 'client_event_id', 'VARCHAR(64)');
                if (!self::indexExists($pdo, 'coffee_events', 'idx_coffee_events_client')) {
                    // See step 4: the check and the CREATE are not atomic.
                    try {
                        $pdo->exec(
                            'CREATE UNIQUE INDEX idx_coffee_events_client
                             ON coffee_events (client_event_id)'
                        );
                    } catch (Throwable $e) {
                        error_log('[coffee] index skipped: ' . $e->getMessage());
                    }
                }
            },
            9 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps().
                self::ensureColumn($pdo, 'users', 'remind_requested_at', 'BIGINT NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'users', 'reminded_month', 'VARCHAR(7)');
            },
            10 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps().
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS rate_limits (
                        bucket VARCHAR(64) PRIMARY KEY,
                        attempts BIGINT NOT NULL DEFAULT 0,
                        window_start BIGINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                self::dropIndex($pdo, 'coffee_events', 'idx_coffee_events_client');
                if (!self::indexExists($pdo, 'coffee_events', 'idx_coffee_events_user_client')) {
                    // A CREATE INDEX that loses a race with a concurrently
                    // starting instance must not take the request down with it.
                    try {
                        $pdo->exec(
                            'CREATE UNIQUE INDEX idx_coffee_events_user_client
                             ON coffee_events (user_id, client_event_id)'
                        );
                    } catch (Throwable $e) {
                        error_log('[coffee] index skipped: ' . $e->getMessage());
                    }
                }
            },
            11 => static function (PDO $pdo): void {
                // See the comment in sqliteSteps().
                self::ensureColumn($pdo, 'users', 'password_hash', 'VARCHAR(255)');
                self::ensureColumn($pdo, 'users', 'password_set_at', 'BIGINT NOT NULL DEFAULT 0');
            },
        ];
    }

    /** Removes an index if it is there; never fails when it is not. */
    private static function dropIndex(PDO $pdo, string $table, string $indexName): void
    {
        if (!self::tableExists($pdo, $table)) {
            return;
        }
        try {
            if (self::driverOf($pdo) === 'mysql') {
                if (self::indexExists($pdo, $table, $indexName)) {
                    $pdo->exec(sprintf(
                        'DROP INDEX %s ON %s',
                        self::quoteIdentifier($pdo, $indexName),
                        self::quoteIdentifier($pdo, $table)
                    ));
                }

                return;
            }
            $pdo->exec('DROP INDEX IF EXISTS ' . self::quoteIdentifier($pdo, $indexName));
        } catch (Throwable $e) {
            error_log('[coffee] index drop skipped: ' . $e->getMessage());
        }
    }

    /**
     * Backfill for schema v5: sets tab_cents and price_cents from the price
     * configured at migration time. The "= 0" condition makes the step
     * idempotent – a second run changes nothing once the values have been
     * filled in (and it does not affect real users without coffees/events
     * either, since the WHERE clause additionally requires coffees > 0).
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
     * Cheap, read-only check whether the schema matches the target version.
     */
    private static function schemaLooksComplete(PDO $pdo): bool
    {
        $userColumns = self::columns($pdo, 'users');
        foreach (['coffees', 'paid_cents', 'name_encrypted', 'name_hash', 'user_handle', 'tab_cents', 'is_admin', 'password_hash'] as $column) {
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

        if (!self::tableExists($pdo, 'settings')) {
            return false;
        }

        if (!self::tableExists($pdo, 'rate_limits')) {
            return false;
        }

        return self::tableExists($pdo, 'link_codes');
    }

    /**
     * Ensures columns and indexes that an older or foreign database may not
     * bring along. Purely additive – never DROP, never CREATE without IF NOT
     * EXISTS (or its MySQL-capable equivalent).
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

        // Uniqueness; the partial indexes tolerate legacy rows without an HMAC.
        // Should an index fail on legacy data, the application stays usable.
        $indexes = [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name_hash ON users (name_hash) WHERE name_hash IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_handle ON users (user_handle) WHERE user_handle IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_credentials_credential_id ON credentials (credential_id)',
            'CREATE INDEX IF NOT EXISTS idx_credentials_user ON credentials (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_ceremonies_challenge ON ceremonies (challenge)',
            'CREATE INDEX IF NOT EXISTS idx_coffee_events_user ON coffee_events (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_coffee_events_user_created ON coffee_events (user_id, created_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_coffee_events_user_client ON coffee_events (user_id, client_event_id) WHERE client_event_id IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_link_codes_hash ON link_codes (code_hash)',
            'CREATE INDEX IF NOT EXISTS idx_link_codes_user ON link_codes (user_id)',
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
     * The same indexes as under SQLite, but without the partial WHERE clause:
     * MySQL/MariaDB permits any number of NULLs in a UNIQUE index anyway, which
     * covers the same case (legacy rows without HMAC/handle).
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
            ['idx_coffee_events_user_client', 'coffee_events', 'UNIQUE', '(user_id, client_event_id)'],
            ['idx_link_codes_hash', 'link_codes', 'UNIQUE', '(code_hash)'],
            ['idx_link_codes_user', 'link_codes', '', '(user_id)'],
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
     * Expected columns per table, driver-dependent only in the type. Used by
     * ensureSchema() as a safety net.
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
                    'remind_requested_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'reminded_month' => 'VARCHAR(7)',
                    'password_hash' => 'VARCHAR(255)',
                    'password_set_at' => 'BIGINT NOT NULL DEFAULT 0',
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
                    'client_event_id' => 'VARCHAR(64)',
                ],
                'settings' => [
                    'value' => 'TEXT',
                ],
                'link_codes' => [
                    'user_id' => 'BIGINT NOT NULL DEFAULT 0',
                    'code_hash' => 'VARCHAR(64)',
                    'created_by' => 'VARCHAR(16)',
                    'used' => 'BIGINT NOT NULL DEFAULT 0',
                    'expires_at' => 'BIGINT NOT NULL DEFAULT 0',
                    'created_at' => 'BIGINT NOT NULL DEFAULT 0',
                ],
                'rate_limits' => [
                    'attempts' => 'BIGINT NOT NULL DEFAULT 0',
                    'window_start' => 'BIGINT NOT NULL DEFAULT 0',
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
                'remind_requested_at' => 'INTEGER NOT NULL DEFAULT 0',
                'reminded_month' => 'TEXT',
                'password_hash' => 'TEXT',
                'password_set_at' => 'INTEGER NOT NULL DEFAULT 0',
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
                'client_event_id' => 'TEXT',
            ],
            'settings' => [
                'value' => 'TEXT',
            ],
            'link_codes' => [
                'user_id' => 'INTEGER NOT NULL DEFAULT 0',
                'code_hash' => 'TEXT',
                'created_by' => 'TEXT',
                'used' => 'INTEGER NOT NULL DEFAULT 0',
                'expires_at' => 'INTEGER NOT NULL DEFAULT 0',
                'created_at' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'rate_limits' => [
                'attempts' => 'INTEGER NOT NULL DEFAULT 0',
                'window_start' => 'INTEGER NOT NULL DEFAULT 0',
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

    /** Does an index of this name exist on this table (MySQL/MariaDB only)? */
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
            // A concurrently started instance was faster – the column exists.
            if (!in_array($column, self::columns($pdo, $table), true)) {
                throw $e;
            }
        }
    }

    /** Identifier quoting is driver-dependent: SQLite "..", MySQL `..`. */
    private static function quoteIdentifier(PDO $pdo, string $identifier): string
    {
        if (self::driverOf($pdo) === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
