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
 * The window is deliberately coarse (one row per counter, reset when the window
 * rolls over): the goal is to make online guessing hopeless, not to meter
 * traffic precisely. A counter is keyed by the endpoint group plus either the
 * caller (allow()) or an arbitrary subject such as an account (allowFor()) --
 * the password login uses both, since neither alone bounds guessing from a
 * pool of addresses against one account.
 */
final class RateLimit
{
    /** Attempts per window for registration and setup. */
    public const REGISTER_MAX = 20;

    /** Attempts per window for login. */
    public const LOGIN_MAX = 30;

    /** Attempts per window for device linking. */
    public const LINK_MAX = 20;

    /**
     * Wrong setup tokens per window. Far tighter than the surrounding
     * register bucket: the setup token is a one-time bootstrap secret whose
     * whole job is to keep a stranger from claiming a fresh installation, and
     * there is no legitimate reason to get it wrong more than a few times.
     */
    public const SETUP_TOKEN_MAX = 5;

    /**
     * Attempts per window for the administrator password login, per caller.
     * Much tighter than LOGIN_MAX: a passkey assertion cannot be guessed at
     * all, a password can.
     */
    public const PASSWORD_MAX = 10;

    /**
     * Attempts per window for the administrator password login, per account
     * and across all callers — so guessing from a pool of addresses is
     * throttled too, not just from one.
     *
     * The trade-off is that anyone can burn this counter and keep the admin
     * from signing in by password for the rest of the window. That is a
     * nuisance rather than a lockout: the passkey path has its own counter and
     * stays available, and the window self-heals after WINDOW seconds.
     */
    public const PASSWORD_ACCOUNT_MAX = 20;

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
        return self::hit(self::bucket($name), $max, $windowSeconds);
    }

    /**
     * Registers one attempt against a counter keyed by the endpoint group and
     * an arbitrary subject INSTEAD of the client address — a per-account
     * counter, which is what makes guessing from a pool of addresses as
     * expensive as guessing from one.
     *
     * The subject is hashed like the address is, so nothing identifying is
     * stored in the table.
     */
    public static function allowFor(
        string $name,
        string $subject,
        int $max,
        int $windowSeconds = self::WINDOW
    ): bool {
        return self::hit(hash('sha256', $name . "\x1f" . $subject), $max, $windowSeconds);
    }

    private static function hit(string $bucket, int $max, int $windowSeconds): bool
    {
        // The test control surface exists precisely to drive these flows in a
        // loop; it already requires testMode plus a shared token.
        if (Config::testMode()) {
            return true;
        }

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

                // A brand-new bucket and a window that has rolled over mean the
                // same thing here: this request is the first attempt of a fresh
                // window.
                if ($row === null || $windowStart + $windowSeconds <= $now) {
                    self::resetWindow($pdo, $bucket, $now, $row === null);

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

    /**
     * Starts a fresh window for one bucket: an INSERT for a bucket seen for
     * the first time, an UPDATE when the previous window has rolled over.
     * Either way this request is the first attempt of the new window.
     */
    private static function resetWindow(PDO $pdo, string $bucket, int $now, bool $isNew): void
    {
        if ($isNew) {
            $pdo->prepare('INSERT INTO rate_limits (bucket, attempts, window_start) VALUES (?, 1, ?)')
                ->execute([$bucket, $now]);

            return;
        }

        $pdo->prepare('UPDATE rate_limits SET attempts = 1, window_start = ? WHERE bucket = ?')
            ->execute([$now, $bucket]);
    }

    /** Answers 429 and ends the request when the limit is exhausted. */
    public static function enforce(string $name, int $max, int $windowSeconds = self::WINDOW): void
    {
        if (!self::allow($name, $max, $windowSeconds)) {
            Http::error('rate_limited', 429);
        }
    }

    /** enforce() against a per-subject counter; see allowFor(). */
    public static function enforceFor(
        string $name,
        string $subject,
        int $max,
        int $windowSeconds = self::WINDOW
    ): void {
        if (!self::allowFor($name, $subject, $max, $windowSeconds)) {
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

    /**
     * The address every per-caller counter is keyed by.
     *
     * `X-Forwarded-For` is read from the RIGHT, never from the left. The
     * left-most entry is the one the *client* sent: a proxy only ever appends
     * the peer it saw, so anything already in the header arrived with the
     * request and is attacker-controlled. Keying on it meant a caller could
     * hand out a fresh counter to itself on every request — one changed header
     * value per attempt and the invite code, the login and the admin password
     * were no longer throttled at all.
     *
     * Counting from the right instead: with `trustedProxyHops` = 1 (one proxy
     * in front of the app) the last entry is what that proxy observed, which is
     * the real peer. Each further trusted hop moves one position left. A header
     * shorter than the configured hop count means fewer proxies than
     * configured, so REMOTE_ADDR — the only value nobody can forge — is used.
     */
    private static function clientAddress(): string
    {
        if (Config::trustProxy()) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $parts = array_values(array_filter(
                    array_map('trim', explode(',', $forwarded)),
                    static fn (string $part): bool => $part !== ''
                ));
                $index = count($parts) - Config::trustedProxyHops();
                if ($index >= 0 && isset($parts[$index])) {
                    return $parts[$index];
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
