<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use Throwable;

/**
 * Datenbankgestützte Einstellungen. Diese haben Vorrang vor config.php (siehe
 * Config-Klasse): ein Admin kann Preis, Einladungscode & Co. zur Laufzeit
 * über die Anwendung ändern, ohne die Konfigurationsdatei anzufassen.
 *
 * Während der allerersten Migration eines frischen Deployments existiert die
 * Tabelle kurzzeitig noch nicht – ein lesender Zugriff in diesem Fenster darf
 * nicht abstürzen, sondern muss wie "keine Einstellung vorhanden" behandelt
 * werden; die Rückfallebene übernimmt dann Config.
 */
final class Settings
{
    /** @var array<string, string>|null Pro-Request-Cache aller Zeilen. */
    private static ?array $cache = null;

    /** Liest eine Einstellung, oder null, falls keine Zeile existiert. */
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
            // Tabelle fehlt noch (erste Migration läuft gerade) oder die
            // Datenbank ist anderweitig nicht erreichbar – ohne Absturz auf
            // "nichts gesetzt" zurückfallen, Config übernimmt den Rest.
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
                // Portables Upsert ohne dialektspezifische Syntax (kein
                // "ON CONFLICT"/"ON DUPLICATE KEY"): erst per SELECT prüfen,
                // ob die Zeile existiert, dann gezielt UPDATE oder INSERT.
                // Bewusst NICHT über rowCount() der UPDATE-Anweisung
                // entschieden – MySQL/MariaDB zählt dort nur tatsächlich
                // geänderte Zeilen, nicht getroffene; ein Schreiben desselben
                // Werts würde sonst fälschlich einen zweiten INSERT auf den
                // bereits vorhandenen Primärschlüssel auslösen.
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

    /** Verwirft den Prozess-lokalen Cache (Tests, und nach jedem Schreiben). */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
