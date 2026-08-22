<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Offene WebAuthn-Ceremonien (Registrierung und Anmeldung).
 *
 * Die Challenge wird serverseitig hinterlegt, ist an genau eine anstehende
 * Ceremonie gebunden und gilt exakt einmal.
 */
final class Ceremonies
{
    public const KIND_REGISTER = 'register';

    public const KIND_LOGIN = 'login';

    /** Verknüpfung eines zweiten Geräts mit einem bestehenden Konto. */
    public const KIND_LINK = 'link';

    /** Gültigkeitsdauer einer Challenge in Sekunden. */
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
     * Holt die Ceremonie zur Challenge und markiert sie sofort als verbraucht.
     * Ein zweiter Aufruf mit derselben Challenge liefert null.
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
                // Ein paralleler Request war schneller.
                return null;
            }

            $createdAt = isset($row['created_at']) && is_numeric($row['created_at']) ? (int) $row['created_at'] : 0;
            if ($createdAt + self::LIFETIME < $now) {
                return null;
            }

            return $row;
        });
    }

    public static function clearForKind(string $kind): void
    {
        Db::transaction(static function (PDO $pdo) use ($kind): void {
            $statement = $pdo->prepare('DELETE FROM ceremonies WHERE kind = ?');
            $statement->execute([$kind]);
        });
    }

    /**
     * Vereinheitlicht die Base64url-Schreibweise, damit Padding-Unterschiede
     * beim Vergleich keine Rolle spielen.
     */
    private static function normalizeChallenge(string $challenge): string
    {
        $raw = Encoding::base64UrlDecode($challenge);

        return $raw === null || $raw === '' ? '' : Encoding::base64UrlEncode($raw);
    }
}
