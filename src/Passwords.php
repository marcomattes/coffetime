<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Optional password login for administrators.
 *
 * This application is passkey-first and stays that way for everybody else:
 * a password can only ever exist on an administrator account, and only an
 * already signed-in administrator can set one on their own account. It exists
 * for the one case WebAuthn cannot cover — a managed workstation whose policy
 * blocks authenticators outright, leaving the person who has to look after the
 * tab with no way in at all.
 *
 * What a leaked password does NOT buy: account names stay sealed. They are
 * stored as RSA ciphertext plus a keyed HMAC, and the private key never
 * touches the server, so the roster remains unreadable to whoever holds the
 * password (see the "Name privacy" section in ARCHITECTURE.md).
 */
final class Passwords
{
    /**
     * Long enough that the throttle in front of the endpoint (RateLimit's
     * PASSWORD_MAX per window) makes online guessing hopeless, short enough
     * to still be typeable on a kitchen tablet.
     */
    public const MIN_LENGTH = 12;

    /** Upper bound so a request body cannot turn into pointless hashing work. */
    public const MAX_LENGTH = 200;

    /**
     * A real hash of a value nobody knows. Verifying against it costs the same
     * as verifying a genuine one, which is what keeps "no such account" and
     * "wrong password" indistinguishable by timing. Never used as a credential:
     * its plaintext was random and was discarded.
     */
    private const DUMMY_HASH = '$2y$12$ZmprTJdUKDLce27B16UAKOTghVz02b4C4aB1PYas8guf9BCCfqw1.';

    /**
     * Normalizes a password into a fixed-size, printable string before it ever
     * reaches password_hash()/password_verify().
     *
     * bcrypt — what PASSWORD_DEFAULT still resolves to on the shared hosts this
     * app targets — silently truncates at 72 bytes and stops at the first NUL
     * byte. Pre-hashing turns any passphrase, of any length and any encoding,
     * into 44 printable characters, so every byte the user typed counts and
     * none is quietly dropped. It is applied on both sides, so stored hashes
     * stay verifiable if PASSWORD_DEFAULT ever changes algorithm.
     */
    private static function prepare(string $password): string
    {
        return base64_encode(hash('sha256', $password, true));
    }

    /** Whether a candidate is acceptable as a new password. */
    public static function isAcceptable(string $password): bool
    {
        // Measured in characters, not bytes: a 12-character passphrase should
        // not be refused for containing umlauts.
        $length = mb_strlen($password);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    /**
     * Hashes a password for storage. Callers must check isAcceptable() first.
     *
     * No failure branch: since PHP 8 password_hash() raises a ValueError for an
     * unusable algorithm instead of returning false, so a broken hashing setup
     * surfaces as a 500 rather than as a stored value nothing can ever verify.
     */
    public static function hash(string $password): string
    {
        return password_hash(self::prepare($password), PASSWORD_DEFAULT);
    }

    /**
     * Verifies a password against a user row.
     *
     * A null row (unknown name) and a row without a password both still run a
     * full verification against DUMMY_HASH, so neither answers measurably
     * faster than a genuine wrong password.
     *
     * @param array<string, mixed>|null $row
     */
    public static function verify(?array $row, string $password): bool
    {
        $stored = $row === null ? '' : self::hashOf($row);
        $candidate = self::prepare($password);

        if ($stored === '') {
            password_verify($candidate, self::DUMMY_HASH);

            return false;
        }

        return password_verify($candidate, $stored);
    }

    /**
     * Whether the stored hash should be replaced — because PHP's default cost
     * or algorithm moved on since it was written. Only meaningful right after
     * a successful verify(), when the plaintext is at hand.
     */
    public static function needsRehash(string $stored): bool
    {
        return $stored !== '' && password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    /** @param array<string, mixed> $row */
    public static function hashOf(array $row): string
    {
        $value = $row['password_hash'] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $row */
    public static function isSet(array $row): bool
    {
        return self::hashOf($row) !== '';
    }

    /**
     * Unix time the current password was set; 0 when there is none.
     *
     * @param array<string, mixed> $row
     */
    public static function setAt(array $row): int
    {
        $value = $row['password_set_at'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
