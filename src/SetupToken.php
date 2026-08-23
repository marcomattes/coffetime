<?php

declare(strict_types=1);

namespace Coffee;

/**
 * One-time secret that gates the first-run setup wizard.
 *
 * `POST /api/setup/init` is necessarily unauthenticated — it runs before any
 * account exists — and it decides the two things an installation can never
 * take back: the RSA public key every name is sealed to, and the invite code.
 * Whoever reaches a freshly uploaded instance first therefore owned it: one
 * request installs *their* key, the account they register next is flagged
 * admin by the first-user rule, and every colleague who signs up afterwards
 * has their name encrypted to a key the operator does not hold. The operator
 * would only see `already_initialized` and could easily read that as "I must
 * have done this earlier".
 *
 * The fix has to survive the wizard's whole reason for existing: that no file
 * has to be edited by hand (README, "Quick start"). So the token is generated
 * by the server rather than configured, and proving you can *read* it stands
 * in for authentication — the same shape Jupyter and GitLab use for their
 * first-run secrets. Reading it requires filesystem access to the deployment,
 * which is exactly the privilege the legitimate operator has and a remote
 * attacker does not.
 *
 * It is written next to the database, which is denied over HTTP (Db::pdo()
 * writes the .htaccess, build-release.sh ships one), and logged once on
 * creation so a container deployment can read it with `docker compose logs`
 * instead of exec-ing into the image. Logging a secret is normally wrong; this
 * one is worthless the moment setup completes, and unreachable-in-practice
 * beats unreachable-in-theory when the alternative is an operator who cannot
 * finish the install.
 *
 * `setupToken` in config.php overrides the file for scripted deployments.
 */
final class SetupToken
{
    private const FILENAME = 'setup-token.txt';

    /**
     * The token this installation expects, creating it on first call.
     * Returns '' when it can neither be read nor written — the caller turns
     * that into an error that names the file, rather than into an open door.
     */
    public static function ensure(): string
    {
        $configured = Config::setupToken();
        if ($configured !== '') {
            return $configured;
        }

        $path = self::path();
        $existing = @file_get_contents($path);
        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }

        $token = bin2hex(random_bytes(16));
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        // Exclusive create: two concurrent first requests must not each write
        // their own token, or the one the operator reads may not be the one
        // the next request compares against.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            $raced = @file_get_contents($path);

            return is_string($raced) && trim($raced) !== '' ? trim($raced) : '';
        }
        @fwrite($handle, $token . "\n");
        @fclose($handle);
        @chmod($path, 0600);

        error_log(
            '[coffee] first-run setup token: ' . $token
            . ' — enter it in the setup wizard. Also stored in ' . $path
        );

        return $token;
    }

    /** Constant-time comparison against the expected token. */
    public static function verify(string $given): bool
    {
        $expected = self::ensure();

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Removes the token file once setup has completed. Best effort: the guard
     * that actually closes the wizard is needsSetup(), and a leftover file
     * grants nothing on an initialized instance.
     */
    public static function clear(): void
    {
        @unlink(self::path());
    }

    public static function path(): string
    {
        return dirname(Config::dbPath()) . '/' . self::FILENAME;
    }
}
