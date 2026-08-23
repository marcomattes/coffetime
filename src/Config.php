<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Access to `config.php` in the tree's root directory.
 *
 * The file is read fresh per request. There is deliberately no file- or
 * database-backed cache: a changed configuration must take effect on the
 * very next request.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $values = null;

    public static function path(): string
    {
        // For tests and alternative deployments: an environment variable
        // overrides the default path when set and non-empty.
        $override = getenv('COFFEE_CONFIG_PATH');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return dirname(__DIR__) . '/config.php';
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        $path = self::path();
        $loaded = [];
        if (is_file($path)) {
            // If OPcache is active, a stale compilation must not be used.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            /** @psalm-suppress UnresolvableInclude */
            $result = require $path;
            if (is_array($result)) {
                $loaded = $result;
            }
        }

        self::$values = $loaded + [
            'priceCents' => 150,
            'invite' => '',
            'admins' => [],
            // Empty means "derive from the request": rpId() and origin()
            // fall back to the validated Host header, so an unconfigured
            // instance works on any host/port (e.g. the quick-start server
            // on localhost:8123). Production should still pin both.
            'rpId' => '',
            'origin' => '',
            'dbPath' => dirname(__DIR__) . '/data/coffee.sqlite',
            'testMode' => false,
            'testToken' => '',
            'adminPublicKey' => '',
            'namePepper' => '',
            // Only set this when a reverse proxy in front of the app strips
            // and re-sets the X-Forwarded-* headers itself. It makes the
            // scheme of a derived origin follow X-Forwarded-Proto, which is
            // what a TLS-terminating proxy needs for the session cookie to
            // get its Secure flag and for WebAuthn origins to match.
            'trustProxy' => false,
            // Offset of the "day" used for streaks, history and the month-end
            // reminder, in minutes east of UTC (Berlin winter = 60). 0 keeps
            // the historical UTC behavior. A fixed offset deliberately does
            // not follow DST -- see ARCHITECTURE.md.
            'dayOffsetMinutes' => 0,
        ];

        return self::$values;
    }

    /**
     * Discards the process-local cache. Only needed within a single request
     * (tests), never across requests.
     */
    public static function forget(): void
    {
        self::$values = null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function priceCents(): int
    {
        // A database setting (set by the assistant or an admin) takes
        // precedence over config.php; if absent, prior behavior is
        // unchanged.
        $fromDb = Settings::get('priceCents');
        if ($fromDb !== null && is_numeric($fromDb)) {
            return (int) $fromDb;
        }

        $value = self::get('priceCents', 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function invite(): string
    {
        $fromDb = Settings::get('invite');
        if ($fromDb !== null) {
            return $fromDb;
        }

        $value = self::get('invite', '');

        return is_string($value) ? $value : '';
    }

    public static function rpId(): string
    {
        $value = self::get('rpId', '');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $derived = self::requestHost(withPort: false);

        return $derived !== '' ? $derived : 'localhost';
    }

    public static function origin(): string
    {
        $value = self::get('origin', '');
        $origin = is_string($value) ? $value : '';
        if ($origin !== '') {
            return $origin;
        }

        $host = self::requestHost(withPort: true);
        if ($host === '') {
            return '';
        }

        // Fallback without explicit configuration: scheme from the HTTPS
        // server variable, host from the Host header. Caution: behind a
        // reverse proxy, the Host header must be trustworthy (e.g. because
        // the proxy sets it rather than passing the client's value
        // through); production deployments should still set origin/rpId
        // explicitly in config.php.
        return self::requestScheme() . '://' . $host;
    }

    /**
     * Scheme for a derived origin. Behind a TLS-terminating proxy the HTTPS
     * server variable is absent, which would silently downgrade the derived
     * origin to http:// -- breaking WebAuthn origin checks and dropping the
     * session cookie's Secure flag. X-Forwarded-Proto fixes that, but it is
     * a client-settable header unless a proxy overwrites it, so it is only
     * consulted when the deployment opts in via trustProxy.
     */
    private static function requestScheme(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        if (self::trustProxy()) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                // A proxy chain may append values ("https, http"); the first
                // one is the scheme the client actually spoke.
                $first = strtolower(trim(explode(',', $forwarded, 2)[0]));
                if ($first === 'https') {
                    return 'https';
                }
            }
        }

        return 'http';
    }

    public static function trustProxy(): bool
    {
        $value = self::get('trustProxy', false);

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Day-boundary offset in seconds east of UTC, used everywhere a Unix
     * timestamp is turned into a calendar day (streaks, history, month-end
     * reminders). Clamped to the real-world range of UTC offsets.
     */
    public static function dayOffsetSeconds(): int
    {
        // is_numeric() already excludes booleans, so a stray `true` in the
        // config falls through to the default rather than becoming 1 minute.
        $value = self::get('dayOffsetMinutes', 0);
        if (!is_numeric($value)) {
            return 0;
        }
        $minutes = (int) $value;
        if ($minutes < -840 || $minutes > 840) {
            return 0;
        }

        return $minutes * 60;
    }

    /**
     * Reads and validates $_SERVER['HTTP_HOST'] for the origin/rpId fallback
     * derivation. Returns '' for a missing or suspicious header (never use
     * it unchecked in a response or a comparison).
     */
    private static function requestHost(bool $withPort): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host) !== 1) {
            return '';
        }

        return $withPort ? $host : explode(':', $host, 2)[0];
    }

    public static function dbPath(): string
    {
        $value = self::get('dbPath', '');

        return is_string($value) && $value !== ''
            ? $value
            : dirname(__DIR__) . '/data/coffee.sqlite';
    }

    /**
     * Normalizes the 'db' option. If absent or unusable, SQLite (via
     * dbPath) is the driver, so existing behavior is unchanged as long as
     * nobody configures 'db'.
     *
     * @return array<string, mixed>
     */
    public static function db(): array
    {
        $value = self::get('db');
        if (!is_array($value)) {
            return ['driver' => 'sqlite'];
        }

        $driver = $value['driver'] ?? 'sqlite';
        if (!is_string($driver) || !in_array($driver, ['sqlite', 'mysql'], true)) {
            return ['driver' => 'sqlite'];
        }
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite'];
        }

        $database = $value['database'] ?? null;
        $user = $value['user'] ?? null;
        if (!is_string($database) || $database === '' || !is_string($user) || $user === '') {
            // Without a database name and user, the configuration is unusable.
            return ['driver' => 'sqlite'];
        }

        $host = $value['host'] ?? '127.0.0.1';
        $port = $value['port'] ?? 3306;
        $password = $value['password'] ?? '';
        $charset = $value['charset'] ?? 'utf8mb4';

        return [
            'driver' => 'mysql',
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_numeric($port) ? (int) $port : 3306,
            'database' => $database,
            'user' => $user,
            'password' => is_string($password) ? $password : '',
            'charset' => is_string($charset) && $charset !== '' ? $charset : 'utf8mb4',
        ];
    }

    /** Convenient access to the active driver without reading the whole array. */
    public static function dbDriver(): string
    {
        $driver = self::db()['driver'] ?? 'sqlite';

        return is_string($driver) ? $driver : 'sqlite';
    }

    /** @return list<string> */
    public static function admins(): array
    {
        $value = self::get('admins', []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $id) {
            if (is_string($id) || is_int($id)) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    /**
     * Checks ONLY the static admin list from config.php. The server-side
     * is_admin flag (set on the first registered user, see Users::create())
     * is deliberately excluded here to avoid forcing an extra database
     * query per call: callers that already hold a loaded user row combine
     * `Config::isAdmin($id) || Users::isAdminRow($row)` instead.
     */
    public static function isAdmin(string $userId): bool
    {
        return in_array($userId, self::admins(), true);
    }

    public static function testMode(): bool
    {
        return self::get('testMode', false) === true || self::get('testMode') === 1 || self::get('testMode') === '1';
    }

    public static function testToken(): string
    {
        $value = self::get('testToken', '');

        return is_string($value) ? $value : '';
    }

    public static function adminPublicKey(): string
    {
        $fromDb = Settings::get('adminPublicKey');
        if ($fromDb !== null) {
            return $fromDb;
        }

        $value = self::get('adminPublicKey', '');

        return is_string($value) ? $value : '';
    }

    public static function namePepper(): string
    {
        $fromDb = Settings::get('namePepper');
        if ($fromDb !== null) {
            return $fromDb;
        }

        $value = self::get('namePepper', '');

        return is_string($value) ? $value : '';
    }

    public static function isSecureOrigin(): bool
    {
        return str_starts_with(strtolower(self::origin()), 'https://');
    }
}
