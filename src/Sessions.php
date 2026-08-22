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

        $session = Db::fetchRow('SELECT * FROM sessions WHERE id = ?', [$id]);
        if ($session === null) {
            return null;
        }

        $now = Clock::now();
        $expiresAt = isset($session['expires_at']) && is_numeric($session['expires_at'])
            ? (int) $session['expires_at']
            : 0;
        if ($expiresAt <= $now) {
            self::destroy($id);

            return null;
        }

        $user = Users::find((string) ($session['user_id'] ?? ''));
        if ($user === null) {
            self::destroy($id);

            return null;
        }

        self::touch($id, $now);
        self::sendCookie($token, self::COOKIE_MAX_AGE);

        return $user;
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
