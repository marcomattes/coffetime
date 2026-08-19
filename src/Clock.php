<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Einzige Zeitquelle der Anwendung.
 *
 * Alles Serverseitige mit Zeitstempel geht durch `Clock::now()`; `time()` wird
 * ausschliesslich hier aufgerufen. Über `/api/test/clock` kann die Uhr für die
 * laufende Serverinstanz verschoben werden.
 *
 * Der Offset liegt in einer Datei neben der Datenbank, damit alle Worker des
 * eingebauten PHP-Servers denselben Wert sehen. Damit er einen Neustart *nicht*
 * überlebt, wird die PID des setzenden Prozesses mitgeschrieben: lebt dieser
 * Prozess nicht mehr, ist der Offset ungültig. Die Worker des eingebauten
 * Servers leben so lange wie der Server selbst.
 */
final class Clock
{
    private static ?int $offsetCache = null;

    public static function now(): int
    {
        return time() + self::offset();
    }

    public static function offset(): int
    {
        if (self::$offsetCache !== null) {
            return self::$offsetCache;
        }

        self::$offsetCache = 0;

        $raw = @file_get_contents(self::offsetFile());
        if ($raw === false || trim($raw) === '') {
            return 0;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) || !isset($data['offset'], $data['pid']) || !is_numeric($data['offset'])) {
            return 0;
        }
        if (!self::processIsAlive((int) $data['pid'])) {
            // Offset einer früheren Serverinstanz: verwerfen.
            @unlink(self::offsetFile());

            return 0;
        }

        self::$offsetCache = (int) $data['offset'];

        return self::$offsetCache;
    }

    public static function setOffset(int $seconds): void
    {
        self::$offsetCache = $seconds;
        $file = self::offsetFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $file,
            (string) json_encode(['offset' => $seconds, 'pid' => getmypid()]),
            LOCK_EX
        );
    }

    private static function offsetFile(): string
    {
        return dirname(Config::dbPath()) . '/clock-offset.json';
    }

    private static function processIsAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if ($pid === getmypid()) {
            return true;
        }
        if (is_dir('/proc/' . $pid)) {
            return true;
        }
        if (is_dir('/proc')) {
            // Linux mit /proc: das Fehlen des Verzeichnisses ist eindeutig.
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
