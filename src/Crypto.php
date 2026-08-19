<?php

declare(strict_types=1);

namespace Coffee;

use InvalidArgumentException;
use SodiumException;

/**
 * Versiegelt Namen gegen den öffentlichen Admin-Schlüssel und bildet den
 * Eindeutigkeits-HMAC.
 *
 * Diese Klasse hat absichtlich keine Abhängigkeiten (kein Composer, keine
 * Datenbank), damit `tests/CryptoTest.php` sie direkt einbinden kann. Sie kann
 * nur versiegeln – der private Schlüssel existiert auf dem Server nicht, es gibt
 * hier also bewusst keine Entschlüsselungsfunktion.
 */
final class Crypto
{
    public const NAME_MAX_LENGTH = 64;

    private string $publicKey;

    public function __construct(string $adminPublicKeyHex, private string $pepper)
    {
        $hex = trim($adminPublicKeyHex);
        if (strlen($hex) !== 64 || preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            throw new InvalidArgumentException('adminPublicKey must be 64 hex characters');
        }
        $binary = hex2bin(strtolower($hex));
        if ($binary === false || strlen($binary) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('adminPublicKey is not a valid X25519 public key');
        }
        $this->publicKey = $binary;
    }

    public static function fromConfig(): self
    {
        return new self(Config::adminPublicKey(), Config::namePepper());
    }

    /**
     * Normalisiert einen Namensteil: Rand- und Mehrfach-Whitespace weg.
     */
    public static function normalizeNamePart(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Verpackt Vor- und Nachname als JSON und versiegelt sie (anonyme
     * Verschlüsselung, X25519 + XSalsa20-Poly1305).
     *
     * @return string Standard-Base64 (RFC 4648, mit Padding) des Chiffrats
     * @throws SodiumException
     */
    public function sealName(string $firstName, string $lastName): string
    {
        $payload = json_encode(
            ['firstName' => $firstName, 'lastName' => $lastName],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return base64_encode(sodium_crypto_box_seal($payload, $this->publicKey));
    }

    /**
     * Deterministischer Fingerabdruck eines Namens für die UNIQUE-Spalte.
     * Gleiche Namen ergeben denselben Wert, verschiedene Namen nicht.
     */
    public function nameHash(string $firstName, string $lastName): string
    {
        $normalized = self::normalizeNamePart($firstName) . "\x1f" . self::normalizeNamePart($lastName);

        return hash_hmac('sha256', $normalized, $this->pepper);
    }
}
