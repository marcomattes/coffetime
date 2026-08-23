<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Thin wrapper around request and response. Every response is JSON except
 * the application shell itself.
 */
final class Http
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /**
     * Upper bound on a request body. Every endpoint here takes a small JSON
     * object; the largest legitimate one is a WebAuthn attestation, which
     * stays far below this. Without a bound, PHP would buffer whatever is
     * sent until memory_limit turns it into a 500.
     */
    private const MAX_BODY_BYTES = 262144;

    /**
     * Content-Security-Policy for the application shell. The shell loads one
     * external script and one external stylesheet from its own origin and
     * carries no inline script or style, so nothing here needs to be
     * loosened with 'unsafe-inline'. (Chart bars are sized through the CSSOM,
     * which CSP does not restrict.)
     */
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "manifest-src 'self'; "
        . "worker-src 'self'; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'none'; "
        . "frame-ancestors 'none'";

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

    /** Path without query string, without duplicate slashes, without trailing slash. */
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
     * Reads the request body as JSON. An empty, malformed, or mislabeled
     * body yields an empty array — no error, no crash.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        // Read one byte past the limit so an oversized body is detected
        // rather than silently truncated into invalid JSON.
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || trim($raw) === '') {
            return self::$body = [];
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            self::$body = [];
            self::error('payload_too_large', 413);
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
            // An API response never legitimately renders or is framed.
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
            header('X-Frame-Options: DENY');
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
            // The shell is the only framable document, and every
            // state-changing control lives on it -- deny framing outright so
            // "Take a coffee" and "Record payment" cannot be clickjacked.
            header('Content-Security-Policy: ' . self::CSP);
            header('X-Frame-Options: DENY');
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
