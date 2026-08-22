<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use Throwable;

/**
 * Database-backed settings. These take precedence over config.php (see the
 * Config class): an admin can change price, invite code, and similar values
 * at runtime through the application, without touching the config file.
 *
 * During the very first migration of a fresh deployment, the table briefly
 * doesn't exist yet — a read in that window must not crash but must be
 * treated as "no setting present", with Config providing the fallback.
 */
final class Settings
{
    /** @var array<string, string>|null Per-request cache of all rows. */
    private static ?array $cache = null;

    /** Reads a setting, or null if no row exists. */
    public static function get(string $name): ?string
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, string> */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = Db::fetchRows('SELECT name, value FROM settings');
        } catch (Throwable $e) {
            // Table doesn't exist yet (first migration in progress) or the
            // database is otherwise unreachable — fall back to "nothing
            // set" without crashing; Config handles the rest.
            return self::$cache = [];
        }

        $values = [];
        foreach ($rows as $row) {
            $key = $row['name'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return self::$cache = $values;
    }

    public static function set(string $name, string $value): void
    {
        self::setMany([$name => $value]);
    }

    /** @param array<string, string> $pairs */
    public static function setMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }

        Db::transaction(static function (PDO $pdo) use ($pairs): void {
            foreach ($pairs as $name => $value) {
                // Portable upsert without dialect-specific syntax (no
                // "ON CONFLICT"/"ON DUPLICATE KEY"): check via SELECT
                // whether the row exists, then issue a targeted UPDATE or
                // INSERT. Deliberately NOT decided via the UPDATE
                // statement's rowCount() — MySQL/MariaDB counts only rows
                // actually changed there, not rows matched; writing the
                // same value again would otherwise wrongly trigger a
                // second INSERT against the already-existing primary key.
                $exists = Db::fetchValue('SELECT 1 FROM settings WHERE name = ?', [$name], $pdo) !== null;
                if ($exists) {
                    $pdo->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute([$value, $name]);
                } else {
                    $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
                }
            }
        });

        self::reset();
    }

    /** Discards the process-local cache (tests, and after every write). */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
