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
    /** Grace period for undoing a booking; see undoWindowSeconds(). */
    public const UNDO_WINDOW_DEFAULT = 300;
    public const UNDO_WINDOW_MIN = 30;
    public const UNDO_WINDOW_MAX = 86400;

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
            // Deliberately `require`, not `require_once`: self::$values above
            // is already the request-local cache, cleared explicitly via
            // forget(). The test suite reloads config by rewriting THIS SAME
            // path with new content and calling forget() between phases (see
            // writeTestConfig() in tests/helpers.php, used repeatedly
            // against one workspace throughout tests/UnitTest.php).
            // require_once tracks inclusion by resolved path, so it would
            // skip the file on every reload after the first and silently
            // return stale (or default) config instead of the new one.
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
            // Overrides the generated first-run setup token (see SetupToken)
            // for scripted deployments. Empty means "use the generated file".
            'setupToken' => '',
            'adminPublicKey' => '',
            'namePepper' => '',
            // Only set this when a reverse proxy in front of the app strips
            // and re-sets the X-Forwarded-* headers itself. It makes the
            // scheme of a derived origin follow X-Forwarded-Proto, which is
            // what a TLS-terminating proxy needs for the session cookie to
            // get its Secure flag and for WebAuthn origins to match.
            'trustProxy' => false,
            // How many proxies sit in front of the app. X-Forwarded-For is
            // read this many entries from the right, because only what a
            // trusted proxy appended is trustworthy -- see
            // RateLimit::clientAddress().
            'trustedProxyHops' => 1,
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

    /**
     * Grace period in seconds during which a freshly booked coffee can still
     * be taken back.
     *
     * Undo exists for the mis-tap and the double tap, not for editing the tab
     * down: without a bound, anyone could walk their own counter back to zero
     * one press at a time. Values outside a sane range (or a non-numeric one)
     * fall back to the default rather than quietly removing the limit again.
     */
    public static function undoWindowSeconds(): int
    {
        $value = self::get('undoWindowSeconds', self::UNDO_WINDOW_DEFAULT);
        if (!is_numeric($value)) {
            return self::UNDO_WINDOW_DEFAULT;
        }
        $seconds = (int) $value;
        if ($seconds < self::UNDO_WINDOW_MIN || $seconds > self::UNDO_WINDOW_MAX) {
            return self::UNDO_WINDOW_DEFAULT;
        }

        return $seconds;
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

    /**
     * PayPal.me handle the "Pay with PayPal" button links to, or '' when the
     * tab is settled some other way – the button is then simply not shown.
     */
    public static function paypalHandle(): string
    {
        $fromDb = Settings::get('paypalHandle');
        if ($fromDb !== null) {
            return self::normalizePaypalHandle($fromDb);
        }

        $value = self::get('paypalHandle', '');

        return is_string($value) ? self::normalizePaypalHandle($value) : '';
    }

    /**
     * Takes what people actually paste – a bare handle, `paypal.me/name`, or
     * the full URL in either of PayPal's two spellings – and returns the bare
     * handle. Anything that is not a usable handle afterwards becomes '': a
     * button leading to a broken PayPal page is worse than no button.
     */
    public static function normalizePaypalHandle(string $raw): string
    {
        $value = trim($raw);
        $value = (string) preg_replace('#^https?://#i', '', $value);
        $value = (string) preg_replace('#^(www\.)?(paypal\.me|paypal\.com/paypalme)/#i', '', $value);
        $value = trim($value, '/');

        // PayPal.me handles are 1-20 ASCII letters and digits. Keeping to that
        // set is also what makes the handle safe to put in a URL unescaped.
        return preg_match('/^[A-Za-z0-9]{1,20}$/', $value) === 1 ? $value : '';
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
     * Number of trusted proxies between the client and the app, used to pick
     * the right entry out of X-Forwarded-For. Clamped to a sane range: a value
     * larger than the real chain would select a client-supplied entry again,
     * which is exactly what reading from the right is meant to prevent.
     */
    public static function trustedProxyHops(): int
    {
        $value = self::get('trustedProxyHops', 1);
        if (!is_numeric($value)) {
            return 1;
        }
        $hops = (int) $value;
        if ($hops < 1 || $hops > 8) {
            return 1;
        }

        return $hops;
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
        if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
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
        if (!is_string($driver) || !in_array($driver, ['sqlite', 'mysql'], true) || $driver === 'sqlite') {
            return ['driver' => 'sqlite'];
        }

        return self::mysqlConfig($value) ?? ['driver' => 'sqlite'];
    }

    /**
     * Builds the mysql driver config once db() has established that a
     * 'mysql' driver was actually requested. Split out purely to keep db()'s
     * return count within the linter's budget; returns null when the
     * configuration is unusable, in which case the caller falls back to
     * sqlite exactly as before.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>|null
     */
    private static function mysqlConfig(array $value): ?array
    {
        $database = $value['database'] ?? null;
        $user = $value['user'] ?? null;
        if (!is_string($database) || $database === '' || !is_string($user) || $user === '') {
            // Without a database name and user, the configuration is unusable.
            return null;
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

    /**
     * Read from config.php only, never from the settings table: the token
     * gates the very request that first writes those settings, so taking it
     * from the database would let the wizard authorize itself.
     */
    public static function setupToken(): string
    {
        $value = self::get('setupToken', '');

        return is_string($value) ? trim($value) : '';
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
