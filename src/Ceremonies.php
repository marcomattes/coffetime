<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Pending WebAuthn ceremonies (registration and login).
 *
 * The challenge is stored server-side, bound to exactly one pending
 * ceremony, and valid for exactly one use.
 */
final class Ceremonies
{
    public const KIND_REGISTER = 'register';

    public const KIND_LOGIN = 'login';

    /** Linking a second device to an existing account. */
    public const KIND_LINK = 'link';

    /** Validity period of a challenge, in seconds. */
    public const LIFETIME = 600;

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(string $kind, string $challenge, string $optionsJson, array $payload = []): void
    {
        $now = Clock::now();
        $normalized = self::normalizeChallenge($challenge);

        Db::transaction(static function (PDO $pdo) use ($kind, $normalized, $optionsJson, $payload, $now): void {
            $statement = $pdo->prepare('DELETE FROM ceremonies WHERE created_at < ? OR challenge = ?');
            $statement->execute([$now - self::LIFETIME, $normalized]);

            $statement = $pdo->prepare(
                'INSERT INTO ceremonies (kind, challenge, options, payload, used, created_at)
                 VALUES (?, ?, ?, ?, 0, ?)'
            );
            $statement->execute([
                $kind,
                $normalized,
                $optionsJson,
                (string) json_encode($payload),
                $now,
            ]);
        });
    }

    /**
     * Fetches the ceremony for the challenge and immediately marks it used.
     * A second call with the same challenge returns null.
     *
     * @return array<string, mixed>|null
     */
    public static function consume(string $kind, string $challenge): ?array
    {
        $normalized = self::normalizeChallenge($challenge);
        if ($normalized === '') {
            return null;
        }
        $now = Clock::now();

        return Db::transaction(static function (PDO $pdo) use ($kind, $normalized, $now): ?array {
            $row = self::claim($pdo, $kind, $normalized);
            if ($row === null) {
                return null;
            }

            $createdAt = isset($row['created_at']) && is_numeric($row['created_at']) ? (int) $row['created_at'] : 0;
            if ($createdAt + self::LIFETIME < $now) {
                return null;
            }

            return $row;
        });
    }

    /**
     * Fetches the unused ceremony for a challenge and atomically marks it
     * used, so a concurrent second call for the same challenge cannot also
     * claim it. Returns null when there is nothing unused to claim.
     *
     * @return array<string, mixed>|null
     */
    private static function claim(PDO $pdo, string $kind, string $normalized): ?array
    {
        $row = Db::fetchRow(
            'SELECT * FROM ceremonies WHERE challenge = ? AND kind = ? AND used = 0',
            [$normalized, $kind],
            $pdo
        );
        if ($row === null) {
            return null;
        }

        $update = $pdo->prepare('UPDATE ceremonies SET used = 1 WHERE id = ? AND used = 0');
        $update->execute([$row['id']]);
        if ($update->rowCount() !== 1) {
            // A concurrent request won the race.
            return null;
        }

        return $row;
    }

    public static function clearForKind(string $kind): void
    {
        Db::transaction(static function (PDO $pdo) use ($kind): void {
            $statement = $pdo->prepare('DELETE FROM ceremonies WHERE kind = ?');
            $statement->execute([$kind]);
        });
    }

    /**
     * Normalizes base64url representation so padding differences don't
     * affect comparison.
     */
    private static function normalizeChallenge(string $challenge): string
    {
        $raw = Encoding::base64UrlDecode($challenge);

        return $raw === null || $raw === '' ? '' : Encoding::base64UrlEncode($raw);
    }
}
