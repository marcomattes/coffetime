<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Zugriff auf `config.php` im Wurzelverzeichnis des Baums.
 *
 * Die Datei wird pro Request frisch gelesen. Es gibt absichtlich keinen Cache
 * in einer Datei oder in der Datenbank: eine geänderte Konfiguration muss beim
 * unmittelbar folgenden Request wirksam sein.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $values = null;

    public static function path(): string
    {
        // Für Tests und alternative Deployments: eine Umgebungsvariable
        // überschreibt den Standardpfad, falls gesetzt und nicht leer.
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
            // Falls OPcache aktiv ist, darf keine veraltete Kompilierung greifen.
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
            'rpId' => 'localhost',
            'origin' => 'http://localhost',
            'dbPath' => dirname(__DIR__) . '/data/coffee.sqlite',
            'testMode' => false,
            'testToken' => '',
            'adminPublicKey' => '',
            'namePepper' => '',
        ];

        return self::$values;
    }

    /**
     * Verwirft den Prozess-lokalen Cache. Wird nur innerhalb eines Requests
     * benötigt (Tests), niemals über Requests hinweg.
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
        // Eine Datenbankeinstellung (vom Assistenten oder einem Admin
        // gesetzt) hat Vorrang vor config.php; fehlt sie, bleibt das
        // bisherige Verhalten unverändert.
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

        // Rückfallebene ohne explizite Konfiguration: Schema aus HTTPS-Server-
        // variable, Host aus dem Host-Header. Achtung – hinter einem
        // Reverse-Proxy muss der Host-Header vertrauenswürdig sein (z. B.
        // weil der Proxy ihn setzt statt ihn vom Client durchzureichen);
        // produktive Deployments sollten origin/rpId weiterhin explizit in
        // config.php setzen.
        $https = $_SERVER['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    /**
     * Liest und validiert $_SERVER['HTTP_HOST'] für die origin-/rpId-
     * Rückfallableitung. Liefert '' bei einem fehlenden oder verdächtigen
     * Header (nie ungeprüft in eine Antwort oder einen Vergleich übernehmen).
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
     * Normalisiert die 'db'-Option. Fehlt sie oder ist sie unbrauchbar, ist
     * SQLite (über dbPath) der Treiber – das bestehende Verhalten bleibt also
     * unverändert, solange niemand 'db' konfiguriert.
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
            // Ohne Datenbankname und Benutzer ist die Konfiguration unbrauchbar.
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

    /** Bequemer Zugriff auf den aktiven Treiber, ohne das ganze Array zu lesen. */
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
     * Prüft NUR die statische Admin-Liste aus config.php. Das serverseitige
     * is_admin-Flag (vom ersten registrierten Nutzer, siehe Users::create())
     * kommt bewusst nicht hier hinein, um pro Aufruf keine zusätzliche
     * Datenbankabfrage zu erzwingen: Aufrufer, die bereits eine geladene
     * Nutzerzeile besitzen, kombinieren stattdessen
     * `Config::isAdmin($id) || Users::isAdminRow($row)`.
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
