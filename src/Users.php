<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Zugriff auf die Benutzer und die daran hängende Arithmetik.
 *
 * Der Zähler ist serverautoritativ: er wird ausschliesslich über atomare
 * `UPDATE ... SET coffees = coffees ± 1` verändert.
 */
final class Users
{
    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        return Db::fetchRow('SELECT * FROM users WHERE id = ?', [(int) $id]);
    }

    /** @return array<string, mixed>|null */
    public static function findByHandle(string $handle): ?array
    {
        if ($handle === '') {
            return null;
        }
        return Db::fetchRow('SELECT * FROM users WHERE user_handle = ?', [$handle]);
    }

    public static function idForNameHash(string $nameHash): ?string
    {
        $id = Db::fetchValue('SELECT id FROM users WHERE name_hash = ?', [$nameHash]);

        return $id === null ? null : (string) $id;
    }

    /**
     * Legt einen Benutzer an. Der Klartextname wird nie gespeichert – nur das
     * versiegelte Chiffrat und der HMAC.
     *
     * @return array<string, mixed> die neue Zeile
     */
    public static function create(
        string $nameEncrypted,
        string $nameHash,
        string $userHandle,
        int $coffees = 0,
        int $paidCents = 0
    ): array {
        $id = Db::transaction(static function (PDO $pdo) use ($nameEncrypted, $nameHash, $userHandle, $coffees, $paidCents): string {
            $statement = $pdo->prepare(
                'INSERT INTO users (name, name_encrypted, name_hash, user_handle, coffees, paid_cents, created_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $nameEncrypted,
                $nameHash,
                $userHandle,
                max(0, $coffees),
                max(0, $paidCents),
                Clock::now(),
            ]);

            return (string) $pdo->lastInsertId();
        });

        $row = self::find($id);

        return $row ?? ['id' => $id, 'coffees' => $coffees, 'paid_cents' => $paidCents];
    }

    /** @return array<string, mixed> */
    public static function addCoffee(string $id): array
    {
        return Db::transaction(static function (PDO $pdo) use ($id): array {
            $statement = $pdo->prepare('UPDATE users SET coffees = coffees + 1 WHERE id = ?');
            $statement->execute([(int) $id]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /** @return array<string, mixed> */
    public static function undoCoffee(string $id): array
    {
        return Db::transaction(static function (PDO $pdo) use ($id): array {
            // Stoppt bei null, wird niemals negativ.
            $statement = $pdo->prepare('UPDATE users SET coffees = coffees - 1 WHERE id = ? AND coffees > 0');
            $statement->execute([(int) $id]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /** @return array<string, mixed> */
    public static function addPayment(string $id, int $amountCents): array
    {
        return Db::transaction(static function (PDO $pdo) use ($id, $amountCents): array {
            $statement = $pdo->prepare('UPDATE users SET paid_cents = paid_cents + ? WHERE id = ?');
            $statement->execute([$amountCents, (int) $id]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /** @return array<string, mixed> */
    private static function rowInTransaction(PDO $pdo, string $id): array
    {
        $row = Db::fetchRow('SELECT * FROM users WHERE id = ?', [(int) $id], $pdo);

        return $row ?? ['id' => $id, 'coffees' => 0, 'paid_cents' => 0];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return Db::fetchRows('SELECT * FROM users ORDER BY id ASC');
    }

    public static function count(): int
    {
        $value = Db::fetchValue('SELECT COUNT(*) AS total FROM users');

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function coffees(array $row): int
    {
        return isset($row['coffees']) && is_numeric($row['coffees']) ? (int) $row['coffees'] : 0;
    }

    public static function paidCents(array $row): int
    {
        return isset($row['paid_cents']) && is_numeric($row['paid_cents']) ? (int) $row['paid_cents'] : 0;
    }

    /**
     * balanceCents = coffees × priceCents − paidCents.
     * Der Preis kommt immer aus der Konfiguration.
     */
    public static function balanceCents(array $row): int
    {
        return self::coffees($row) * Config::priceCents() - self::paidCents($row);
    }

    /** @return array<string, mixed> */
    public static function adminView(array $row): array
    {
        $encrypted = $row['name_encrypted'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'nameEncrypted' => is_string($encrypted) ? $encrypted : '',
            'coffees' => self::coffees($row),
            'balanceCents' => self::balanceCents($row),
        ];
    }

    /**
     * Anonyme Statistik: Gesamtzahl, Anzahl Benutzer, eigener Rang und die
     * Verteilung der Zählerstände. Keine Namen, keine fremden IDs.
     *
     * @return array<string, mixed>
     */
    public static function stats(string $selfId): array
    {
        $rows = Db::fetchRows('SELECT id, coffees FROM users ORDER BY coffees DESC, id ASC');

        $total = 0;
        foreach ($rows as $row) {
            $total += self::coffees($row);
        }

        $distribution = [];
        $rank = null;
        $previousCoffees = null;
        $previousRank = 0;
        foreach ($rows as $index => $row) {
            $coffees = self::coffees($row);
            // Gleichstand teilt den Rang (Wettkampfwertung).
            $currentRank = $previousCoffees !== null && $coffees === $previousCoffees
                ? $previousRank
                : $index + 1;
            $previousCoffees = $coffees;
            $previousRank = $currentRank;

            $distribution[] = ['rank' => $currentRank, 'coffees' => $coffees];
            if ((string) ($row['id'] ?? '') === $selfId) {
                $rank = $currentRank;
            }
        }

        return [
            'total' => $total,
            'users' => count($rows),
            'rank' => $rank,
            'distribution' => $distribution,
        ];
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9]{1,18}$/', $id) === 1;
    }

    public static function newHandle(): string
    {
        return Encoding::base64UrlEncode(random_bytes(16));
    }
}
