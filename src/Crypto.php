<?php

declare(strict_types=1);

namespace Coffee;

use InvalidArgumentException;

/** Encrypts names for the administrator and creates uniqueness HMACs. */
final class Crypto
{
    public const NAME_MAX_LENGTH = 48;
    public const CIPHER_PREFIX = 'rsa-oaep-sha1:';

    /**
     * Shortest pepper accepted as an HMAC key. The wizard generates 64 hex
     * characters; this floor only exists to catch an unset or obviously
     * throwaway value before it silently weakens every name fingerprint.
     */
    private const PEPPER_MIN_LENGTH = 8;

    /**
     * Values shipped in config.example.php (or otherwise public). Using one of
     * these as the HMAC key makes every name_hash recomputable by anyone, so
     * they are refused outright rather than merely discouraged.
     *
     * @var list<string>
     */
    private const PEPPER_PLACEHOLDERS = [
        'change-me-to-a-long-random-value',
        'change-me',
        'REPLACE_WITH_YOUR_PEPPER',
    ];

    /**
     * RSA-OAEP(SHA-1) plaintext capacity for a 4096-bit key:
     * 512 - 2*20 - 2 = 470 bytes.
     */
    private const MAX_SEALED_PAYLOAD_BYTES = 470;

    /** @var \OpenSSLAsymmetricKey */
    private $publicKey;

    public function __construct(string $adminPublicKey, private string $pepper)
    {
        $this->publicKey = self::loadPublicKey($adminPublicKey);
        self::assertUsablePepper($pepper);
    }

    public static function fromConfig(): self
    {
        return new self(Config::adminPublicKey(), Config::namePepper());
    }

    /**
     * Validates a candidate admin public key without needing a pepper. The
     * setup wizard checks the uploaded key before any pepper exists, so it
     * must not have to invent a throwaway one that would fail validation.
     *
     * @throws InvalidArgumentException when the key is unusable
     */
    public static function assertValidPublicKey(string $adminPublicKey): void
    {
        self::loadPublicKey($adminPublicKey);
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function loadPublicKey(string $adminPublicKey)
    {
        $trimmed = trim($adminPublicKey);
        // openssl_pkey_get_public() also accepts a "file://…" (or bare path)
        // argument, not just PEM text. adminPublicKey is user-supplied — the
        // setup wizard takes a pasted/uploaded key — so without this guard a
        // crafted "public key" could turn into an arbitrary server-side file
        // read instead of a PEM parse. Requiring an inline PEM block up front
        // closes that off before OpenSSL ever sees the value.
        if (!str_starts_with($trimmed, '-----BEGIN ')) {
            throw new InvalidArgumentException('adminPublicKey must be a valid PEM-encoded RSA public key');
        }
        $key = openssl_pkey_get_public($trimmed);
        if ($key === false) {
            throw new InvalidArgumentException('adminPublicKey must be a valid PEM-encoded RSA public key');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 4096) {
            throw new InvalidArgumentException('adminPublicKey must be an RSA key of at least 4096 bits');
        }

        return $key;
    }

    /**
     * A missing or placeholder pepper is a silent privacy failure: name_hash
     * is a keyed fingerprint, so a publicly known key makes every registered
     * name recomputable from a database copy. Fail loudly instead.
     */
    private static function assertUsablePepper(string $pepper): void
    {
        if (strlen($pepper) < self::PEPPER_MIN_LENGTH) {
            throw new InvalidArgumentException(
                'namePepper must be set to a random secret of at least ' . self::PEPPER_MIN_LENGTH . ' characters'
            );
        }
        foreach (self::PEPPER_PLACEHOLDERS as $placeholder) {
            if (hash_equals($placeholder, $pepper)) {
                throw new InvalidArgumentException('namePepper is still the example placeholder; set a random secret');
            }
        }
    }

    /**
     * Trims a name part, collapses repeated whitespace, and drops control
     * characters. The last step matters for more than tidiness: control
     * characters JSON-encode to six-byte \uXXXX escapes, so without it a
     * name of NAME_MAX_LENGTH characters could exceed the RSA-OAEP plaintext
     * capacity and turn a validation problem into a failed encryption.
     */
    public static function normalizeNamePart(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        // \p{C} covers control, format, surrogate, private-use and unassigned
        // code points; the whitespace collapse above already ran, so real
        // separators are preserved as plain spaces.
        $value = preg_replace('/\p{C}+/u', '', $value) ?? $value;

        return trim($value);
    }

    /** Encrypts the name using RSA-OAEP so Web Crypto can decrypt it locally. */
    public function sealName(string $firstName, string $lastName): string
    {
        $payload = json_encode(
            ['firstName' => $firstName, 'lastName' => $lastName],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        // Refuse oversized input explicitly rather than letting OpenSSL fail:
        // the caller turns this into a 400, not a 500.
        if (strlen($payload) > self::MAX_SEALED_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('The name is too long to encrypt');
        }
        $encrypted = '';
        if (!openssl_public_encrypt($payload, $encrypted, $this->publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new CryptoException('Could not encrypt the name');
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
