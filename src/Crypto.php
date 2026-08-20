<?php

declare(strict_types=1);

namespace Coffee;

use InvalidArgumentException;
use RuntimeException;

/** Encrypts names for the administrator and creates uniqueness HMACs. */
final class Crypto
{
    public const NAME_MAX_LENGTH = 48;
    public const CIPHER_PREFIX = 'rsa-oaep-sha1:';

    /** @var \OpenSSLAsymmetricKey */
    private $publicKey;

    public function __construct(string $adminPublicKey, private string $pepper)
    {
        $key = openssl_pkey_get_public(trim($adminPublicKey));
        if ($key === false) {
            throw new InvalidArgumentException('adminPublicKey must be a valid PEM-encoded RSA public key');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 4096) {
            throw new InvalidArgumentException('adminPublicKey must be an RSA key of at least 4096 bits');
        }
        $this->publicKey = $key;
    }

    public static function fromConfig(): self
    {
        return new self(Config::adminPublicKey(), Config::namePepper());
    }

    /** Trims a name part and collapses repeated whitespace. */
    public static function normalizeNamePart(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** Encrypts the name using RSA-OAEP so Web Crypto can decrypt it locally. */
    public function sealName(string $firstName, string $lastName): string
    {
        $payload = json_encode(
            ['firstName' => $firstName, 'lastName' => $lastName],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $encrypted = '';
        if (!openssl_public_encrypt($payload, $encrypted, $this->publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Could not encrypt the name');
        }
        return self::CIPHER_PREFIX . base64_encode($encrypted);
    }

    /** Creates a deterministic, non-reversible name fingerprint. */
    public function nameHash(string $firstName, string $lastName): string
    {
        $normalized = self::normalizeNamePart($firstName) . "\x1f" . self::normalizeNamePart($lastName);
        return hash_hmac('sha256', $normalized, $this->pepper);
    }
}
