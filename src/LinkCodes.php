<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Einmalcodes zum Verknüpfen eines zweiten Geräts mit einem bestehenden
 * Konto: selbst erzeugt ("self", z. B. für ein zweites eigenes Gerät) oder
 * von einem Admin erzeugt ("admin", bei Geräteverlust). Es existiert je
 * Konto zu jedem Zeitpunkt höchstens ein unverbrauchter Code – ein neu
 * erzeugter Code entwertet einen zuvor erzeugten sofort.
 *
 * Wie bei Ceremonies wird niemals der Klartextcode gespeichert, nur sein
 * SHA-256-Hash. Der Code wird dem Benutzer genau einmal angezeigt.
 */
final class LinkCodes
{
    /** Unzweideutiges Alphabet: kein I/O/0/1, um Verwechslungen zu vermeiden. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Länge des Codes ohne Trennstrich. */
    private const CODE_LENGTH = 8;

    /** Gültigkeitsdauer eines selbst erzeugten Codes: 15 Minuten. */
    public const SELF_TTL = 900;

    /** Gültigkeitsdauer eines von einem Admin erzeugten Codes: 1 Stunde. */
    public const ADMIN_TTL = 3600;

    /**
     * Erzeugt einen neuen Code für den Benutzer. Ein vorhandener,
     * unverbrauchter Code desselben Benutzers wird dabei gelöscht – es gibt
     * je Konto immer höchstens einen aktiven Code.
     *
     * @return array{code: string, expiresAt: int}
     */
    public static function create(string $userId, string $createdBy, int $ttlSeconds): array
    {
        $raw = self::randomCode();
        $hash = hash('sha256', $raw);
        $now = Clock::now();
        $expiresAt = $now + $ttlSeconds;

        Db::transaction(static function (PDO $pdo) use ($userId, $hash, $createdBy, $expiresAt, $now): void {
            // Nur ein aktiver Code je Konto: ein neuer Code entwertet jeden
            // noch unverbrauchten Vorgänger.
            $pdo->prepare('DELETE FROM link_codes WHERE user_id = ? AND used = 0')
                ->execute([(int) $userId]);

            $statement = $pdo->prepare(
                'INSERT INTO link_codes (user_id, code_hash, created_by, used, expires_at, created_at)
                 VALUES (?, ?, ?, 0, ?, ?)'
            );
            $statement->execute([(int) $userId, $hash, $createdBy, $expiresAt, $now]);
        });

        return ['code' => self::format($raw), 'expiresAt' => $expiresAt];
    }

    /**
     * Normalisiert eine Benutzereingabe: Grossbuchstaben, alles ausserhalb
     * des Alphabets entfernt (Trennstriche, Kleinschreibung, Leerzeichen
     * spielen also keine Rolle). Liefert '', wenn danach nicht genau
     * CODE_LENGTH Zeichen übrig bleiben.
     */
    public static function normalize(string $code): string
    {
        $upper = strtoupper($code);
        $filtered = preg_replace('/[^' . preg_quote(self::ALPHABET, '/') . ']/', '', $upper) ?? '';

        return strlen($filtered) === self::CODE_LENGTH ? $filtered : '';
    }

    /**
     * Liefert die Zeile zu einem gültigen (unverbrauchten, nicht
     * abgelaufenen) Code, ohne ihn zu verbrauchen.
     *
     * @return array<string, mixed>|null
     */
    public static function peek(string $code): ?array
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return null;
        }

        $row = Db::fetchRow(
            'SELECT * FROM link_codes WHERE code_hash = ? AND used = 0',
            [hash('sha256', $normalized)]
        );
        if ($row === null || self::isExpired($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Verbraucht den Code atomar – ein zweiter Aufruf mit demselben Code
     * liefert null. Guardierte UPDATE ... WHERE used = 0 mit rowCount-Prüfung,
     * analog zu Ceremonies::consume().
     *
     * @return array<string, mixed>|null
     */
    public static function consume(string $code): ?array
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return null;
        }
        $hash = hash('sha256', $normalized);

        return Db::transaction(static function (PDO $pdo) use ($hash): ?array {
            $row = Db::fetchRow('SELECT * FROM link_codes WHERE code_hash = ? AND used = 0', [$hash], $pdo);
            if ($row === null || self::isExpired($row)) {
                return null;
            }

            $update = $pdo->prepare('UPDATE link_codes SET used = 1 WHERE id = ? AND used = 0');
            $update->execute([$row['id']]);
            if ($update->rowCount() !== 1) {
                // Ein paralleler Request war schneller.
                return null;
            }

            return $row;
        });
    }

    /** @param array<string, mixed> $row */
    private static function isExpired(array $row): bool
    {
        $expiresAt = isset($row['expires_at']) && is_numeric($row['expires_at']) ? (int) $row['expires_at'] : 0;

        return $expiresAt < Clock::now();
    }

    private static function randomCode(): string
    {
        $chars = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }

        return $chars;
    }

    /** 'ABCDEFGH' -> 'ABCD-EFGH', zur Anzeige an den Benutzer. */
    private static function format(string $raw): string
    {
        return substr($raw, 0, 4) . '-' . substr($raw, 4);
    }
}
