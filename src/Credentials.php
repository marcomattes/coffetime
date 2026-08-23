<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\TrustPath\TrustPath;

/**
 * Stored passkeys: credential ID, public key, signature counter, and owner.
 */
final class Credentials
{
    public static function store(string $userId, CredentialRecord $record): void
    {
        Db::transaction(static function (PDO $pdo) use ($userId, $record): void {
            self::storeIn($pdo, $userId, $record);
        });
    }

    /**
     * Inserts the passkey on a connection that is already inside a
     * transaction. Registration uses this to create the user and the
     * credential as one unit: a user row without a passkey would permanently
     * reserve the name (and, for the very first user, the admin flag) while
     * being impossible to sign in as.
     */
    public static function storeIn(PDO $pdo, string $userId, CredentialRecord $record): void
    {
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

    public static function count(): int
    {
        $value = Db::fetchValue('SELECT COUNT(*) AS total FROM credentials');

        return is_numeric($value) ? (int) $value : 0;
    }

    /** Number of passkeys on a single account, for the "N devices" display. */
    public static function countForUser(string $userId): int
    {
        $value = Db::fetchValue('SELECT COUNT(*) AS total FROM credentials WHERE user_id = ?', [(int) $userId]);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Builds from the row the `CredentialRecord` the library needs to
     * verify an assertion.
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
                    // Falls back to the empty trust path.
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
     * Writes back the verified signature counter.
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
