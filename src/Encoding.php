<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Base64url ohne Padding – das Transportformat von WebAuthn.
 */
final class Encoding
{
    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $value = strtr(trim($value), '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
