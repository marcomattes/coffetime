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

        self::$offsetCache = self::readPersistedOffset() ?? 0;

        return self::$offsetCache;
    }

    /**
     * Reads and validates the offset file, discarding it if it belongs to a
     * process that is no longer alive. Returns null when there is nothing
     * usable — a missing file, an unparsable one, or a stale one.
     */
    private static function readPersistedOffset(): ?int
    {
        $raw = @file_get_contents(self::offsetFile());
        $data = $raw === false || trim($raw) === '' ? null : json_decode(trim($raw), true);
        if (!is_array($data) || !isset($data['offset'], $data['pid']) || !is_numeric($data['offset'])) {
            return null;
        }
        if (!self::processIsAlive((int) $data['pid'])) {
            // Offset from a previous server instance: discard it.
            @unlink(self::offsetFile());

            return null;
        }

        return (int) $data['offset'];
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

        return self::procDirAlive($pid) ?? self::posixAlive($pid);
    }

    /**
     * Checks /proc directly, which is conclusive on Linux. Returns null when
     * /proc itself does not exist, so the caller falls back to another
     * method instead of reporting the process dead.
     */
    private static function procDirAlive(int $pid): ?bool
    {
        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        // Linux with /proc: the directory's absence is conclusive.
        return is_dir('/proc') ? false : null;
    }

    /**
     * Last resort where /proc does not exist (e.g. macOS): signal 0 probes
     * whether the process can be signaled without actually sending one.
     * False when posix_kill() itself is unavailable.
     */
    private static function posixAlive(int $pid): bool
    {
        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
