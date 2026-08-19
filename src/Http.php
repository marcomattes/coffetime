<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Kleine Hülle um Request und Response. Jede Antwort ist JSON – ausser der
 * Anwendungsrumpf selbst.
 */
final class Http
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /** @var array<string, mixed>|null */
    private static ?array $body = null;

    private static bool $responded = false;

    public static function hasResponded(): bool
    {
        return self::$responded;
    }

    public static function method(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return is_string($method) ? strtoupper($method) : 'GET';
    }

    /** Pfad ohne Querystring, ohne doppelte Slashes, ohne Trailing-Slash. */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Liest den Request-Body als JSON. Ein leerer, fehlerhafter oder falsch
     * deklarierter Body ergibt ein leeres Array – kein Fehler, kein Absturz.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true, 64);
        self::$body = is_array($decoded) ? $decoded : [];

        return self::$body;
    }

    /** @param array<string, mixed> $data */
    public static function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        $encoded = json_encode($data, self::JSON_FLAGS);
        echo $encoded === false ? '{"error":"encoding_failed"}' : $encoded;
        self::finish();
    }

    public static function error(string $code, int $status): never
    {
        self::json(['error' => $code], $status);
    }

    public static function html(string $markup, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        echo $markup;
        self::finish();
    }

    private static function finish(): never
    {
        self::$responded = true;
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        exit;
    }
}
