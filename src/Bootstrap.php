<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Loads the application classes and ensures PHP output (notices, warnings,
 * stack traces) never leaks into a response body.
 */
final class Bootstrap
{
    private static bool $done = false;

    public static function init(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        // Never display errors; they are logged exclusively.
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        @ini_set('html_errors', '0');
        @ini_set('log_errors', '1');
        @ini_set('zend.exception_ignore_args', '1');
        error_reporting(E_ALL);

        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'Coffee\\')) {
                return;
            }
            $relative = substr($class, strlen('Coffee\\'));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        // testMode turns /api/test/* into a full authentication bypass and a
        // one-request data wipe, gated only by a shared token. It is meant for
        // the test suites; if it is ever left on in production, say so loudly
        // in the log rather than failing silently open.
        if (Config::testMode()) {
            error_log(
                '[coffee] WARNING: testMode is enabled — /api/test/* can sign in as any user'
                . ' and delete all data. Disable it outside of test environments.'
            );
        }

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            // Messages suppressed with @ stay suppressed, even from the log.
            if ((error_reporting() & $severity) === 0) {
                return true;
            }
            // Everything else is swallowed and logged; the library raises
            // E_USER_DEPRECATED among others, which must not abort the
            // request or reach the response.
            error_log(sprintf('[coffee] php-error %d: %s in %s:%d', $severity, $message, $file, $line));

            return true;
        });
    }
}
