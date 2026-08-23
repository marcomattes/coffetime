<?php

declare(strict_types=1);

namespace Coffee;

use PDO;
use Throwable;

/**
 * Fixed-window rate limiting for the unauthenticated endpoints.
 *
 * Without it, the invite code (as short as four characters) and the
 * registration/login ceremonies can be hammered as fast as the network
 * allows. The counter lives in the database rather than in memory because the
 * built-in server, php-fpm and Apache all serve requests from several
 * processes that share nothing else.
 *
 * The window is deliberately coarse (one row per caller per endpoint group,
 * reset when the window rolls over): the goal is to make online guessing
 * hopeless, not to meter traffic precisely.
 */
final class RateLimit
{
    /** Attempts per window for registration and setup. */
    public const REGISTER_MAX = 20;

    /** Attempts per window for login. */
    public const LOGIN_MAX = 30;

    /** Attempts per window for device linking. */
    public const LINK_MAX = 20;

    /** Length of one window: 10 minutes. */
    public const WINDOW = 600;

    /**
     * Registers one attempt and reports whether it is still within the limit.
     *
     * Fails open: if the counter cannot be read or written (a database that
     * is momentarily unavailable), the request proceeds rather than locking
     * everybody out of their own coffee tab.
     */
    public static function allow(string $name, int $max, int $windowSeconds = self::WINDOW): bool
    {
        // The test control surface exists precisely to drive these flows in a
        // loop; it already requires testMode plus a shared token.
        if (Config::testMode()) {
            return true;
        }

        $bucket = self::bucket($name);
        $now = Clock::now();

        try {
            return Db::transaction(static function (PDO $pdo) use ($bucket, $max, $windowSeconds, $now): bool {
                // Opportunistic cleanup so the table cannot grow without bound.
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                    ->execute([$now - ($windowSeconds * 4)]);

                $row = Db::fetchRow(
                    'SELECT attempts, window_start FROM rate_limits WHERE bucket = ?',
                    [$bucket],
                    $pdo
                );

                $windowStart = $row !== null && isset($row['window_start']) && is_numeric($row['window_start'])
                    ? (int) $row['window_start']
                    : 0;
                $attempts = $row !== null && isset($row['attempts']) && is_numeric($row['attempts'])
                    ? (int) $row['attempts']
                    : 0;

                if ($row === null) {
                    $pdo->prepare('INSERT INTO rate_limits (bucket, attempts, window_start) VALUES (?, 1, ?)')
                        ->execute([$bucket, $now]);

                    return true;
                }

                if ($windowStart + $windowSeconds <= $now) {
                    // The previous window has rolled over: start a new one.
                    $pdo->prepare('UPDATE rate_limits SET attempts = 1, window_start = ? WHERE bucket = ?')
                        ->execute([$now, $bucket]);

                    return true;
                }

                if ($attempts >= $max) {
                    return false;
                }

                $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = ?')
                    ->execute([$bucket]);

                return true;
            });
        } catch (Throwable $e) {
            error_log('[coffee] rate limit unavailable: ' . $e->getMessage());

            return true;
        }
    }

    /** Answers 429 and ends the request when the limit is exhausted. */
    public static function enforce(string $name, int $max, int $windowSeconds = self::WINDOW): void
    {
        if (!self::allow($name, $max, $windowSeconds)) {
            Http::error('rate_limited', 429);
        }
    }

    /**
     * One counter per endpoint group and caller. The client address is hashed
     * together with the group so the table never stores a bare IP address.
     */
    private static function bucket(string $name): string
    {
        return hash('sha256', $name . "\x1f" . self::clientAddress());
    }

    private static function clientAddress(): string
    {
        if (Config::trustProxy()) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                // Left-most entry is the original client; the rest are proxies.
                $first = trim(explode(',', $forwarded, 2)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
