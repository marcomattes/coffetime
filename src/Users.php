<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Access to the users and the arithmetic attached to them.
 *
 * The counter is server-authoritative: it is only ever changed through atomic
 * `UPDATE ... SET coffees = coffees ± 1`.
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
     * Creates a user. The cleartext name is never stored – only the sealed
     * ciphertext and the HMAC.
     *
     * @return array<string, mixed> the new row
     */
    public static function create(
        string $nameEncrypted,
        string $nameHash,
        string $userHandle,
        int $coffees = 0,
        int $paidCents = 0
    ): array {
        // Seed/legacy semantics: inherited coffees are valued at the currently
        // configured price, because there are no individual events (and hence
        // no individual prices) for them.
        $coffees = max(0, $coffees);
        $tabCents = $coffees * Config::priceCents();

        $id = Db::transaction(static function (PDO $pdo) use ($nameEncrypted, $nameHash, $userHandle, $coffees, $paidCents, $tabCents): string {
            // The very first user of an installation automatically becomes
            // admin – without this step, after the setup wizard (no
            // adminPublicKey/admins in config.php any more) nobody would be
            // able to change price or invite code. The count runs in the same
            // transaction as the INSERT so that no concurrent second "first"
            // user can come into existence.
            $countBefore = Db::fetchValue('SELECT COUNT(*) AS total FROM users', [], $pdo);
            $isFirstUser = is_numeric($countBefore) && (int) $countBefore === 0;

            $statement = $pdo->prepare(
                'INSERT INTO users (name, name_encrypted, name_hash, user_handle, coffees, paid_cents, tab_cents, created_at, is_admin)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $nameEncrypted,
                $nameHash,
                $userHandle,
                $coffees,
                max(0, $paidCents),
                $tabCents,
                Clock::now(),
                $isFirstUser ? 1 : 0,
            ]);

            return (string) $pdo->lastInsertId();
        });

        $row = self::find($id);

        return $row ?? ['id' => $id, 'coffees' => $coffees, 'paid_cents' => $paidCents, 'tab_cents' => $tabCents];
    }

    /**
     * Books a coffee at the currently configured price. The price is read once
     * at the start and frozen both in tab_cents and on the event itself – later
     * price changes therefore never apply retroactively to coffees that have
     * already been booked.
     *
     * $clientEventId drains the offline queue: the client assigns the ID before
     * sending and may repeat the same booking arbitrarily often (poor Wi-Fi,
     * duplicate delivery) without booking twice. An already known ID returns
     * the current state unchanged – no counter increment, no new event.
     *
     * @return array<string, mixed>
     */
    public static function addCoffee(string $id, ?string $clientEventId = null): array
    {
        $price = Config::priceCents();

        return Db::transaction(static function (PDO $pdo) use ($id, $price, $clientEventId): array {
            if ($clientEventId !== null) {
                $existing = Db::fetchRow(
                    'SELECT 1 FROM coffee_events WHERE client_event_id = ?',
                    [$clientEventId],
                    $pdo
                );
                if ($existing !== null) {
                    // Repeated delivery of the same booking: return the current
                    // state unchanged, book nothing again.
                    return self::rowInTransaction($pdo, $id);
                }
            }

            $statement = $pdo->prepare(
                'UPDATE users SET coffees = coffees + 1, tab_cents = tab_cents + ? WHERE id = ?'
            );
            $statement->execute([$price, (int) $id]);
            // Event for the streak display and for the price of this booking –
            // one record per booked coffee.
            $event = $pdo->prepare(
                'INSERT INTO coffee_events (user_id, created_at, price_cents, client_event_id) VALUES (?, ?, ?, ?)'
            );
            $event->execute([(int) $id, Clock::now(), $price, $clientEventId]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Books a payment. Additive and atomic, never negative – overly large
     * negative amounts are clamped at zero instead of underflowing the counter.
     *
     * @return array<string, mixed>
     */
    public static function addPayment(string $id, int $amountCents): array
    {
        return Db::transaction(static function (PDO $pdo) use ($id, $amountCents): array {
            // Portable instead of MAX(0, ...): MySQL/MariaDB knows MAX() only
            // as an aggregate, not as a two-argument scalar function.
            $statement = $pdo->prepare(
                'UPDATE users SET paid_cents = CASE WHEN paid_cents + ? < 0 THEN 0 ELSE paid_cents + ? END WHERE id = ?'
            );
            $statement->execute([$amountCents, $amountCents, (int) $id]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Undoes the last booking – both the counter and the price booked for it.
     * The price comes from the most recently booked event, not from the current
     * configuration: only that keeps an intervening price change free of
     * retroactive effect. If the event is missing (legacy data from before
     * schema v5), the currently configured price is deducted instead.
     *
     * @return array<string, mixed>
     */
    public static function undoCoffee(string $id): array
    {
        $fallbackPrice = Config::priceCents();

        return Db::transaction(static function (PDO $pdo) use ($id, $fallbackPrice): array {
            // Stops at zero, never goes negative.
            $statement = $pdo->prepare('UPDATE users SET coffees = coffees - 1 WHERE id = ? AND coffees > 0');
            $statement->execute([(int) $id]);
            if ($statement->rowCount() > 0) {
                // Take back the most recently booked event, not an arbitrary
                // one – and read its price before deleting it.
                $event = Db::fetchRow(
                    'SELECT id, price_cents FROM coffee_events WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                    [(int) $id],
                    $pdo
                );
                $refund = $event !== null && isset($event['price_cents']) && is_numeric($event['price_cents'])
                    ? (int) $event['price_cents']
                    : $fallbackPrice;

                if ($event !== null) {
                    $pdo->prepare('DELETE FROM coffee_events WHERE id = ?')->execute([$event['id']]);
                }

                // Portable instead of MAX(0, ...), as in addPayment: never negative.
                $pdo->prepare(
                    'UPDATE users SET tab_cents = CASE WHEN tab_cents - ? < 0 THEN 0 ELSE tab_cents - ? END WHERE id = ?'
                )->execute([$refund, $refund, (int) $id]);
            }

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Current streak in days: number of consecutive days up to today (or up to
     * yesterday, if nothing has been booked today) with at least one coffee.
     * Purely cosmetic, without influence on coffees/balanceCents.
     */
    public static function streakDays(string $id): int
    {
        $dayExpr = Db::dayExpr('created_at');
        $rows = Db::fetchRows(
            "SELECT DISTINCT {$dayExpr} AS day FROM coffee_events WHERE user_id = ?",
            [(int) $id]
        );
        $days = [];
        foreach ($rows as $row) {
            if (isset($row['day']) && is_string($row['day']) && $row['day'] !== '') {
                $days[$row['day']] = true;
            }
        }
        if ($days === []) {
            return 0;
        }

        $streak = 0;
        $cursor = Clock::now();
        if (!isset($days[gmdate('Y-m-d', $cursor)])) {
            $cursor -= 86400;
        }
        while (isset($days[gmdate('Y-m-d', $cursor)])) {
            $streak++;
            $cursor -= 86400;
        }

        return $streak;
    }

    /**
     * Coffee history for the last $days calendar days (today included), counted
     * per day. Missing days are filled with 0, sorted ascending.
     *
     * @return array{today: int, days: list<array{date: string, coffees: int}>}
     */
    public static function history(string $id, int $days = 28): array
    {
        $now = Clock::now();
        $cutoff = $now - $days * 86400;

        $dayExpr = Db::dayExpr('created_at');
        $rows = Db::fetchRows(
            "SELECT {$dayExpr} AS day, COUNT(*) AS n
             FROM coffee_events WHERE user_id = ? AND created_at >= ? GROUP BY day",
            [(int) $id, $cutoff]
        );
        $counts = [];
        foreach ($rows as $row) {
            if (isset($row['day']) && is_string($row['day']) && $row['day'] !== '') {
                $counts[$row['day']] = isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
            }
        }

        $out = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = gmdate('Y-m-d', $now - $offset * 86400);
            $out[] = ['date' => $day, 'coffees' => $counts[$day] ?? 0];
        }

        return [
            'today' => $counts[gmdate('Y-m-d', $now)] ?? 0,
            'days' => $out,
        ];
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

    /** @param array<string, mixed> $row */
    public static function coffees(array $row): int
    {
        return isset($row['coffees']) && is_numeric($row['coffees']) ? (int) $row['coffees'] : 0;
    }

    /** @param array<string, mixed> $row */
    public static function paidCents(array $row): int
    {
        return isset($row['paid_cents']) && is_numeric($row['paid_cents']) ? (int) $row['paid_cents'] : 0;
    }

    /**
     * Sum over all booked coffees, each at the price it was booked at.
     *
     * @param array<string, mixed> $row
     */
    public static function tabCents(array $row): int
    {
        return isset($row['tab_cents']) && is_numeric($row['tab_cents']) ? (int) $row['tab_cents'] : 0;
    }

    /**
     * balanceCents = tabCents − paidCents.
     * tabCents sums the prices of the individual bookings as of the moment each
     * was booked – a later price change does not revalue coffees that have
     * already been booked.
     *
     * @param array<string, mixed> $row
     */
    public static function balanceCents(array $row): int
    {
        return self::tabCents($row) - self::paidCents($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function adminView(array $row): array
    {
        $encrypted = $row['name_encrypted'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'nameEncrypted' => is_string($encrypted) ? $encrypted : '',
            'coffees' => self::coffees($row),
            'paidCents' => self::paidCents($row),
            'balanceCents' => self::balanceCents($row),
        ];
    }

    /**
     * Anonymous statistics: grand total, number of users, own rank and the
     * distribution of counter values. No names, no foreign IDs.
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
            // Ties share a rank (competition ranking).
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

    // ---------------------------------------------------------- Reminders ---

    /**
     * How many days into the following month a month-end notice that has not
     * been shown yet is still caught up (device was off, app stayed closed).
     */
    private const REMINDER_CATCHUP_DAYS = 7;

    /**
     * The month ('YYYY-MM') for which a month-end notice would be due NOW: on
     * the last day of a month that month itself, during the first
     * REMINDER_CATCHUP_DAYS days of the following month still the previous
     * month, otherwise none. Depends on $now alone (UTC, like every day
     * boundary in this app).
     */
    public static function reminderMonthTag(int $now): ?string
    {
        $day = (int) gmdate('j', $now);
        if ($day === (int) gmdate('t', $now)) {
            return gmdate('Y-m', $now);
        }
        if ($day <= self::REMINDER_CATCHUP_DAYS) {
            // Going back $day days always lands in the previous month, whatever
            // the time of day.
            return gmdate('Y-m', $now - $day * 86400);
        }

        return null;
    }

    /**
     * Timestamp of the outstanding admin reminder, 0 = none outstanding.
     *
     * @param array<string, mixed> $row
     */
    public static function remindRequestedAt(array $row): int
    {
        $value = $row['remind_requested_at'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Most recently acknowledged month-end notice ('YYYY-MM'), '' = none yet.
     *
     * @param array<string, mixed> $row
     */
    public static function remindedMonth(array $row): string
    {
        $value = $row['reminded_month'] ?? '';

        return is_string($value) ? $value : '';
    }

    /** Queues an admin reminder; a second one replaces the first. */
    public static function requestReminder(string $id): void
    {
        Db::transaction(static function (PDO $pdo) use ($id): void {
            $pdo->prepare('UPDATE users SET remind_requested_at = ? WHERE id = ?')
                ->execute([Clock::now(), (int) $id]);
        });
    }

    /**
     * Acknowledges reminders that were shown. The admin reminder is cleared
     * only if the acknowledged timestamp is still the outstanding one – a
     * reminder queued between fetch and acknowledgement is thus kept instead of
     * being cleared unseen.
     */
    public static function ackReminders(string $id, ?string $month, ?int $adminRequestedAt): void
    {
        Db::transaction(static function (PDO $pdo) use ($id, $month, $adminRequestedAt): void {
            if ($month !== null) {
                $pdo->prepare('UPDATE users SET reminded_month = ? WHERE id = ?')
                    ->execute([$month, (int) $id]);
            }
            if ($adminRequestedAt !== null) {
                $pdo->prepare('UPDATE users SET remind_requested_at = 0 WHERE id = ? AND remind_requested_at = ?')
                    ->execute([(int) $id, $adminRequestedAt]);
            }
        });
    }

    /**
     * Checks the is_admin flag on an already loaded user row – without an extra
     * database query. Complements Config::isAdmin() (the static list from
     * config.php); callers usually combine both.
     *
     * @param array<string, mixed> $row
     */
    public static function isAdminRow(array $row): bool
    {
        $value = $row['is_admin'] ?? 0;

        return is_numeric($value) && (int) $value === 1;
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
