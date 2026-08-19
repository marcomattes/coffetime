<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\TrustPath\TrustPath;

/**
 * Gespeicherte Passkeys: Credential-ID, öffentlicher Schlüssel, Signaturzähler
 * und Besitzer.
 */
final class Credentials
{
    public static function store(string $userId, CredentialRecord $record): void
    {
        Db::transaction(static function (PDO $pdo) use ($userId, $record): void {
            $statement = $pdo->prepare(
                'INSERT INTO credentials
                    (user_id, credential_id, public_key, sign_count, aaguid, transports,
                     attestation_type, trust_path, created_at, last_used_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                (int) $userId,
                Encoding::base64UrlEncode($record->publicKeyCredentialId),
                Encoding::base64UrlEncode($record->credentialPublicKey),
                $record->counter,
                $record->aaguid->__toString(),
                (string) json_encode(array_values($record->transports)),
                $record->attestationType,
                (string) json_encode(WebAuthnService::serializer()->normalize($record->trustPath, 'json')),
                Clock::now(),
                Clock::now(),
            ]);
        });
    }

    public static function exists(string $credentialIdB64u): bool
    {
        return Db::fetchRow('SELECT 1 AS found FROM credentials WHERE credential_id = ?', [$credentialIdB64u]) !== null;
    }

    /** @return array<string, mixed>|null */
    public static function findByCredentialId(string $credentialIdB64u): ?array
    {
        return Db::fetchRow('SELECT * FROM credentials WHERE credential_id = ?', [$credentialIdB64u]);
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(string $userId): array
    {
        return Db::fetchRows('SELECT * FROM credentials WHERE user_id = ? ORDER BY id ASC', [(int) $userId]);
    }

    public static function count(): int
    {
        $value = Db::fetchValue('SELECT COUNT(*) AS total FROM credentials');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Baut aus der Zeile den `CredentialRecord`, den die Bibliothek zur Prüfung
     * einer Assertion braucht.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     */
    public static function toRecord(array $row, array $user): ?CredentialRecord
    {
        $credentialId = Encoding::base64UrlDecode((string) ($row['credential_id'] ?? ''));
        $publicKey = Encoding::base64UrlDecode((string) ($row['public_key'] ?? ''));
        $handle = Encoding::base64UrlDecode((string) ($user['user_handle'] ?? ''));
        if ($credentialId === null || $publicKey === null || $handle === null || $handle === '') {
            return null;
        }

        $transports = json_decode((string) ($row['transports'] ?? '[]'), true);
        if (!is_array($transports)) {
            $transports = [];
        }

        return CredentialRecord::create(
            $credentialId,
            'public-key',
            array_values(array_filter($transports, 'is_string')),
            (string) ($row['attestation_type'] ?? 'none'),
            self::trustPath($row),
            self::aaguid($row),
            $publicKey,
            $handle,
            isset($row['sign_count']) && is_numeric($row['sign_count']) ? (int) $row['sign_count'] : 0
        );
    }

    /** @param array<string, mixed> $row */
    private static function trustPath(array $row): TrustPath
    {
        $raw = $row['trust_path'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['type'])) {
                try {
                    $path = WebAuthnService::serializer()->denormalize($decoded, TrustPath::class, 'json');
                    if ($path instanceof TrustPath) {
                        return $path;
                    }
                } catch (\Throwable) {
                    // Fällt auf den leeren Pfad zurück.
                }
            }
        }

        return EmptyTrustPath::create();
    }

    /** @param array<string, mixed> $row */
    private static function aaguid(array $row): Uuid
    {
        $raw = $row['aaguid'] ?? null;
        if (is_string($raw) && Uuid::isValid($raw)) {
            return Uuid::fromString($raw);
        }

        return Uuid::fromString('00000000-0000-0000-0000-000000000000');
    }

    /**
     * Schreibt den geprüften Signaturzähler zurück.
     */
    public static function updateSignCount(string $rowId, int $signCount): void
    {
        Db::transaction(static function (PDO $pdo) use ($rowId, $signCount): void {
            $statement = $pdo->prepare(
                'UPDATE credentials SET sign_count = ?, last_used_at = ? WHERE id = ?'
            );
            $statement->execute([$signCount, Clock::now(), (int) $rowId]);
        });
    }
}
