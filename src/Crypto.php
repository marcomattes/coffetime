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

    /** Single message for every way an admin public key can be unusable. */
    private const KEY_ERROR = 'adminPublicKey must be a valid PEM-encoded RSA public key';

    /**
     * Splits a PEM block into its label and its base64 body, anchored at both
     * ends so nothing may precede or follow the block. The body is restricted
     * to the base64 alphabet and line breaks, which is what makes the parse a
     * parse rather than a "does it look like a key" check.
     */
    private const PEM_PATTERN = '/\\A-----BEGIN (?P<label>[A-Z ]+)-----\\s*'
        . '(?P<body>[A-Za-z0-9+\\/=\\s]*?)\\s*'
        . '-----END (?P=label)-----\\z/';

    /** PEM body line length; 64 base64 characters is what OpenSSL emits. */
    private const PEM_LINE_LENGTH = 64;

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
        $key = openssl_pkey_get_public(self::canonicalPem($adminPublicKey));
        if ($key === false) {
            throw new InvalidArgumentException(self::KEY_ERROR);
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 4096) {
            throw new InvalidArgumentException('adminPublicKey must be an RSA key of at least 4096 bits');
        }

        return $key;
    }

    /**
     * Rebuilds a PEM block from scratch instead of handing OpenSSL whatever
     * the caller sent.
     *
     * openssl_pkey_get_public() does not only parse PEM text: given a
     * "file://…" argument it reads that path off the server instead. The admin
     * public key is user-supplied – the setup wizard takes a pasted or
     * uploaded key – so passing it straight through would let a crafted
     * "public key" turn a key parse into an arbitrary file read.
     *
     * Rejecting suspicious input is not enough for that; the value handed to
     * OpenSSL is therefore not derived from the input as a string at all. The
     * label comes from a fixed allow-list of literals, and the body is decoded
     * from base64 and re-encoded, so the result is provably
     * "-----BEGIN <literal>-----", base64 characters, "-----END <literal>-----"
     * and nothing else – a shape no path can take. Along the way this also
     * rejects trailing junk, a non-base64 body and unknown labels, none of
     * which a prefix check would have caught.
     */
    private static function canonicalPem(string $adminPublicKey): string
    {
        if (preg_match(self::PEM_PATTERN, trim($adminPublicKey), $matches) !== 1) {
            throw new InvalidArgumentException(self::KEY_ERROR);
        }

        // Literal on both sides of the arm: the label that reaches the output
        // is this file's own constant text, never the matched substring.
        $label = match ($matches['label']) {
            'PUBLIC KEY' => 'PUBLIC KEY',
            'RSA PUBLIC KEY' => 'RSA PUBLIC KEY',
            default => throw new InvalidArgumentException(self::KEY_ERROR),
        };

        $der = base64_decode((string) preg_replace('/\\s+/', '', $matches['body']), true);
        if ($der === false || $der === '') {
            throw new InvalidArgumentException(self::KEY_ERROR);
        }

        return '-----BEGIN ' . $label . "-----\n"
            . chunk_split(base64_encode($der), self::PEM_LINE_LENGTH, "\n")
            . '-----END ' . $label . "-----\n";
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
