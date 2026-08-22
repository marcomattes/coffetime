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
        // Seed-/Altbestandssemantik: geerbte Kaffees werden zum aktuell
        // konfigurierten Preis bewertet, da es für sie keine Einzelereignisse
        // (und damit keine Einzelpreise) gibt.
        $coffees = max(0, $coffees);
        $tabCents = $coffees * Config::priceCents();

        $id = Db::transaction(static function (PDO $pdo) use ($nameEncrypted, $nameHash, $userHandle, $coffees, $paidCents, $tabCents): string {
            // Der allererste Benutzer einer Installation wird automatisch
            // Admin – ohne diesen Schritt gäbe es nach dem Einrichtungs-
            // assistenten (keine adminPublicKey/admins mehr in config.php)
            // niemanden, der Preis oder Einladungscode ändern könnte. Die
            // Zählung läuft in derselben Transaktion wie das INSERT, damit
            // kein gleichzeitiger zweiter erster Benutzer entstehen kann.
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
     * Bucht einen Kaffee zum aktuell konfigurierten Preis. Der Preis wird
     * einmal zu Beginn gelesen und sowohl in tab_cents als auch am
     * Ereignis selbst festgeschrieben – spätere Preisänderungen wirken sich
     * damit nie rückwirkend auf schon gebuchte Kaffees aus.
     *
     * $clientEventId trägt die Offline-Warteschlange ab: der Client vergibt
     * die ID vor dem Absenden und kann dieselbe Buchung beliebig oft
     * wiederholen (schlechtes WLAN, doppelte Zustellung), ohne doppelt zu
     * buchen. Eine bereits bekannte ID liefert unverändert den aktuellen
     * Stand zurück – kein Zähler-Inkrement, kein neues Ereignis.
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
                    // Wiederholte Zustellung derselben Buchung: unverändert
                    // den aktuellen Stand zurückgeben, nichts erneut buchen.
                    return self::rowInTransaction($pdo, $id);
                }
            }

            $statement = $pdo->prepare(
                'UPDATE users SET coffees = coffees + 1, tab_cents = tab_cents + ? WHERE id = ?'
            );
            $statement->execute([$price, (int) $id]);
            // Ereignis für die Serienanzeige und den Preis dieser Buchung –
            // ein Datensatz je gebuchtem Kaffee.
            $event = $pdo->prepare(
                'INSERT INTO coffee_events (user_id, created_at, price_cents, client_event_id) VALUES (?, ?, ?, ?)'
            );
            $event->execute([(int) $id, Clock::now(), $price, $clientEventId]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Bucht eine Zahlung. Additiv und atomar, niemals negativ – zu grosse
     * negative Beträge werden bei Null gekappt statt den Zähler zu unterlaufen.
     *
     * @return array<string, mixed>
     */
    public static function addPayment(string $id, int $amountCents): array
    {
        return Db::transaction(static function (PDO $pdo) use ($id, $amountCents): array {
            // Portabel statt MAX(0, ...): MySQL/MariaDB kennt MAX() nur als
            // Aggregatfunktion, nicht als Zwei-Argumente-Skalarfunktion.
            $statement = $pdo->prepare(
                'UPDATE users SET paid_cents = CASE WHEN paid_cents + ? < 0 THEN 0 ELSE paid_cents + ? END WHERE id = ?'
            );
            $statement->execute([$amountCents, $amountCents, (int) $id]);

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Macht die letzte Buchung rückgängig – sowohl den Zähler als auch den
     * dafür verbuchten Preis. Der Preis kommt aus dem zuletzt gebuchten
     * Ereignis, nicht aus der aktuellen Konfiguration: nur so bleibt eine
     * zwischenzeitliche Preisänderung ohne rückwirkenden Effekt. Fehlt ein
     * Ereignis (Altbestand vor Schema v5), wird ersatzweise der aktuell
     * konfigurierte Preis abgezogen.
     *
     * @return array<string, mixed>
     */
    public static function undoCoffee(string $id): array
    {
        $fallbackPrice = Config::priceCents();

        return Db::transaction(static function (PDO $pdo) use ($id, $fallbackPrice): array {
            // Stoppt bei null, wird niemals negativ.
            $statement = $pdo->prepare('UPDATE users SET coffees = coffees - 1 WHERE id = ? AND coffees > 0');
            $statement->execute([(int) $id]);
            if ($statement->rowCount() > 0) {
                // Nur das zuletzt gebuchte Ereignis zurücknehmen, nicht irgendeins –
                // und dessen Preis vor dem Löschen auslesen.
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

                // Portabel statt MAX(0, ...), analog zu addPayment: nie negativ.
                $pdo->prepare(
                    'UPDATE users SET tab_cents = CASE WHEN tab_cents - ? < 0 THEN 0 ELSE tab_cents - ? END WHERE id = ?'
                )->execute([$refund, $refund, (int) $id]);
            }

            return self::rowInTransaction($pdo, $id);
        });
    }

    /**
     * Aktuelle Serie in Tagen: Anzahl aufeinanderfolgender Tage bis heute (oder
     * bis gestern, falls heute noch nichts gebucht wurde) mit mindestens einem
     * Kaffee. Rein kosmetisch, ohne Einfluss auf coffees/balanceCents.
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
     * Kaffeeverlauf der letzten $days Kalendertage (inklusive heute), tagweise
     * gezählt. Fehlende Tage werden mit 0 aufgefüllt, aufsteigend sortiert.
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

    public static function coffees(array $row): int
    {
        return isset($row['coffees']) && is_numeric($row['coffees']) ? (int) $row['coffees'] : 0;
    }

    public static function paidCents(array $row): int
    {
        return isset($row['paid_cents']) && is_numeric($row['paid_cents']) ? (int) $row['paid_cents'] : 0;
    }

    /** Summe der Preise aller gebuchten Kaffees, zum jeweiligen Buchungspreis. */
    public static function tabCents(array $row): int
    {
        return isset($row['tab_cents']) && is_numeric($row['tab_cents']) ? (int) $row['tab_cents'] : 0;
    }

    /**
     * balanceCents = tabCents − paidCents.
     * tabCents summiert die Preise der einzelnen Buchungen zum jeweiligen
     * Buchungszeitpunkt – eine spätere Preisänderung bewertet keine bereits
     * gebuchten Kaffees neu.
     */
    public static function balanceCents(array $row): int
    {
        return self::tabCents($row) - self::paidCents($row);
    }

    /** @return array<string, mixed> */
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

    // ------------------------------------------------------- Erinnerungen ---

    /**
     * Wie viele Tage in den Folgemonat hinein ein noch nicht gezeigter
     * Monatsende-Hinweis nachgeholt wird (Gerät war aus, App blieb zu).
     */
    private const REMINDER_CATCHUP_DAYS = 7;

    /**
     * Der Monat ('YYYY-MM'), für den JETZT ein Monatsende-Hinweis fällig
     * wäre: am letzten Tag eines Monats dieser Monat selbst, in den ersten
     * REMINDER_CATCHUP_DAYS Tagen des Folgemonats noch der Vormonat, sonst
     * keiner. Rein von $now abhängig (UTC, wie alle Tagesgrenzen der App).
     */
    public static function reminderMonthTag(int $now): ?string
    {
        $day = (int) gmdate('j', $now);
        if ($day === (int) gmdate('t', $now)) {
            return gmdate('Y-m', $now);
        }
        if ($day <= self::REMINDER_CATCHUP_DAYS) {
            // $day Tage zurück landet immer im Vormonat, egal zu welcher Uhrzeit.
            return gmdate('Y-m', $now - $day * 86400);
        }

        return null;
    }

    /** Zeitpunkt der offenen Admin-Erinnerung, 0 = keine offen. */
    public static function remindRequestedAt(array $row): int
    {
        $value = $row['remind_requested_at'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** Zuletzt bestätigter Monatsende-Hinweis ('YYYY-MM'), '' = noch keiner. */
    public static function remindedMonth(array $row): string
    {
        $value = $row['reminded_month'] ?? '';

        return is_string($value) ? $value : '';
    }

    /** Merkt eine Admin-Erinnerung vor; eine zweite ersetzt die erste. */
    public static function requestReminder(string $id): void
    {
        Db::transaction(static function (PDO $pdo) use ($id): void {
            $pdo->prepare('UPDATE users SET remind_requested_at = ? WHERE id = ?')
                ->execute([Clock::now(), (int) $id]);
        });
    }

    /**
     * Bestätigt gezeigte Erinnerungen. Die Admin-Erinnerung wird nur
     * gelöscht, wenn der bestätigte Zeitstempel noch der offene ist – eine
     * zwischen Abruf und Bestätigung neu vorgemerkte Erinnerung bleibt so
     * erhalten statt ungesehen mitgelöscht zu werden.
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
     * Prüft das is_admin-Flag auf einer bereits geladenen Nutzerzeile – ohne
     * zusätzliche Datenbankabfrage. Ergänzt Config::isAdmin() (statische
     * Liste aus config.php); Aufrufer kombinieren i. d. R. beides.
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
