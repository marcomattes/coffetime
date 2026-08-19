<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Lädt die Anwendungsklassen und stellt sicher, dass niemals PHP-Ausgaben
 * (Notices, Warnings, Stacktraces) in einen Response-Body gelangen.
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

        // Niemals Fehler ausgeben – sie werden ausschliesslich geloggt.
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
                require $file;
            }
        });

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            // Mit @ unterdrückte Meldungen bleiben unterdrückt – auch im Log.
            if ((error_reporting() & $severity) === 0) {
                return true;
            }
            // Alles andere wird verschluckt und geloggt; die Bibliothek löst
            // u. a. E_USER_DEPRECATED aus, was den Request nicht abbrechen und
            // erst gar nicht im Response landen darf.
            error_log(sprintf('[coffee] php-error %d: %s in %s:%d', $severity, $message, $file, $line));

            return true;
        });
    }
}
