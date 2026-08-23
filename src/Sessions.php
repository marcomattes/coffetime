<?php

declare(strict_types=1);

namespace Coffee;

use PDO;

/**
 * Server-side sessions in SQLite.
 *
 * The cookie holds nothing but a random, opaque token (base64url of 32
 * random bytes). The database stores only its SHA-256 hash, so a copy of
 * the file reveals no valid tokens.
 */
final class Sessions
{
    public const COOKIE_NAME = 'coffee_session';

    /** Cookie lifetime (one year). */
    public const COOKIE_MAX_AGE = 31536000;

    /** Server-side idle lifetime of a session: 30 days. */
    public const IDLE_LIFETIME = 2592000;

    /**
     * Hard ceiling on a session's age: 180 days. Without it, the sliding
     * idle window means a token that keeps being used never expires, so a
     * stolen one stays valid indefinitely.
     */
    public const ABSOLUTE_LIFETIME = 15552000;

    public static function start(string $userId): string
    {
        $token = Encoding::base64UrlEncode(random_bytes(32));
        $id = self::tokenId($token);
        $now = Clock::now();

        Db::transaction(static function (PDO $pdo) use ($id, $userId, $now): void {
            // Opportunistically clean up expired sessions that were never logged out.
            $pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([$now]);
            $statement = $pdo->prepare(
                'INSERT INTO sessions (id, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$id, (int) $userId, $now, $now + self::IDLE_LIFETIME]);
        });

        self::sendCookie($token, self::COOKIE_MAX_AGE);

        return $token;
    }

    /**
     * Reads the session from the cookie, discards expired rows, and extends
     * a valid session — in the database, not just in the cookie.
     *
     * @return array<string, mixed>|null the associated user row
     */
    public static function currentUser(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }
        $id = self::tokenId($token);
        $now = Clock::now();

        $user = self::sessionUser($id, $now);
        if ($user === null) {
            return null;
        }

        self::touch($id, $now);
        self::sendCookie($token, self::COOKIE_MAX_AGE);

        return $user;
    }

    /**
     * The user behind a session id, or null if the session does not exist,
     * is expired, is past its absolute ceiling, or points at a user that no
     * longer exists. Destroys the row in every case but the first — there is
     * nothing to destroy for an id that was never a valid session.
     *
     * @return array<string, mixed>|null
     */
    private static function sessionUser(string $id, int $now): ?array
    {
        $session = Db::fetchRow('SELECT * FROM sessions WHERE id = ?', [$id]);
        if ($session === null) {
            return null;
        }

        $user = self::isExpired($session, $now) ? null : Users::find((string) ($session['user_id'] ?? ''));
        if ($user === null) {
            self::destroy($id);
        }

        return $user;
    }

    /**
     * Whether a session row is past either the sliding idle window or the
     * hard absolute ceiling (ABSOLUTE_LIFETIME) — the ceiling exists so a
     * token that keeps being used never expires.
     *
     * @param array<string, mixed> $session
     */
    private static function isExpired(array $session, int $now): bool
    {
        $expiresAt = isset($session['expires_at']) && is_numeric($session['expires_at'])
            ? (int) $session['expires_at']
            : 0;
        if ($expiresAt <= $now) {
            return true;
        }

        $createdAt = isset($session['created_at']) && is_numeric($session['created_at'])
            ? (int) $session['created_at']
            : 0;

        // Past the hard ceiling the session ends regardless of activity.
        return $createdAt > 0 && $createdAt + self::ABSOLUTE_LIFETIME <= $now;
    }

    private static function touch(string $id, int $now): void
    {
        Db::transaction(static function (PDO $pdo) use ($id, $now): void {
            $statement = $pdo->prepare('UPDATE sessions SET expires_at = ? WHERE id = ?');
            $statement->execute([$now + self::IDLE_LIFETIME, $id]);
        });
    }

    /** Ends only the current session. */
    public static function logoutCurrent(): void
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($token) && $token !== '') {
            self::destroy(self::tokenId($token));
        }
        self::clearCookie();
    }

    /**
     * Ends every session of one account, optionally sparing one (the caller's
     * freshly created session). This is what makes an admin-issued recovery
     * link actually recover an account: whoever holds the lost device is
     * signed out instead of keeping a valid session for another 30 days.
     */
    public static function destroyForUser(string $userId, ?string $exceptToken = null): void
    {
        $exceptId = $exceptToken !== null && $exceptToken !== '' ? self::tokenId($exceptToken) : null;

        Db::transaction(static function (PDO $pdo) use ($userId, $exceptId): void {
            if ($exceptId === null) {
                $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([(int) $userId]);

                return;
            }
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND id <> ?')
                ->execute([(int) $userId, $exceptId]);
        });
    }

    public static function destroy(string $id): void
    {
        Db::transaction(static function (PDO $pdo) use ($id): void {
            $statement = $pdo->prepare('DELETE FROM sessions WHERE id = ?');
            $statement->execute([$id]);
        });
    }

    public static function count(): int
    {
        $value = Db::fetchValue('SELECT COUNT(*) AS total FROM sessions');

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function tokenId(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Sets the session cookie. The header is built manually so `Max-Age`
     * matches the intended lifetime exactly.
     */
    private static function sendCookie(string $token, int $maxAge): void
    {
        if (headers_sent()) {
            return;
        }
        $parts = [
            self::COOKIE_NAME . '=' . $token,
            'Max-Age=' . $maxAge,
            'Expires=' . gmdate('D, d M Y H:i:s \\G\\M\\T', Clock::now() + $maxAge),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if (Config::isSecureOrigin()) {
            $parts[] = 'Secure';
        }
        header('Set-Cookie: ' . implode('; ', $parts), true);
    }

    private static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        $parts = [
            self::COOKIE_NAME . '=',
            'Max-Age=0',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if (Config::isSecureOrigin()) {
            $parts[] = 'Secure';
        }
        header('Set-Cookie: ' . implode('; ', $parts), true);
    }
}
