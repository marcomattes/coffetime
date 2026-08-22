<?php

declare(strict_types=1);

namespace Coffee;

/**
 * The application's single source of time.
 *
 * Every server-side timestamp goes through `Clock::now()`; `time()` is
 * called only here. `/api/test/clock` can shift the clock for the running
 * server instance.
 *
 * The offset is stored in a file next to the database so every worker of
 * the built-in PHP server sees the same value. For the offset to *not*
 * survive a restart, the PID of the process that set it is recorded
 * alongside it: once that process is gone, the offset is invalid. Workers
 * of the built-in server live as long as the server itself.
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
            // Offset from a previous server instance: discard it.
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
            // Linux with /proc: the directory's absence is conclusive.
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
