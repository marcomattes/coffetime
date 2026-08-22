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
        $value = self::get('priceCents', 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function invite(): string
    {
        $value = self::get('invite', '');

        return is_string($value) ? $value : '';
    }

    public static function rpId(): string
    {
        $value = self::get('rpId', '');

        return is_string($value) && $value !== '' ? $value : 'localhost';
    }

    public static function origin(): string
    {
        $value = self::get('origin', '');

        return is_string($value) ? $value : '';
    }

    public static function dbPath(): string
    {
        $value = self::get('dbPath', '');

        return is_string($value) && $value !== ''
            ? $value
            : dirname(__DIR__) . '/data/coffee.sqlite';
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
        $value = self::get('adminPublicKey', '');

        return is_string($value) ? $value : '';
    }

    public static function namePepper(): string
    {
        $value = self::get('namePepper', '');

        return is_string($value) ? $value : '';
    }

    public static function isSecureOrigin(): bool
    {
        return str_starts_with(strtolower(self::origin()), 'https://');
    }
}
