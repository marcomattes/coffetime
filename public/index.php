<?php

declare(strict_types=1);

/**
 * Front-Controller der Kaffeeliste.
 *
 * Läuft ohne Router-Skript hinter dem eingebauten PHP-Server: jede Anfrage, für
 * die keine Datei existiert, landet hier und wird aus REQUEST_URI geroutet.
 */

// Nichts darf ausgegeben werden, was nicht ausdrücklich Response ist.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
ob_start();

require __DIR__ . '/../src/Bootstrap.php';

use Coffee\Api;
use Coffee\Bootstrap;
use Coffee\Http;

Bootstrap::init();

/**
 * Letzte Verteidigungslinie: kein Stacktrace, keine PHP-Meldung, niemals HTML
 * im Fehlerfall – immer JSON mit echtem Status.
 */
$fail = static function (string $reason): void {
    error_log('[coffee] ' . $reason);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '{"error":"server_error"}';
};

set_exception_handler(static function (Throwable $error) use ($fail): void {
    $fail('uncaught: ' . $error::class . ': ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
});

register_shutdown_function(static function () use ($fail): void {
    $last = error_get_last();
    if ($last === null || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (Http::hasResponded()) {
        return;
    }
    $fail('fatal: ' . $last['message']);
});

// Die einzige Composer-Abhängigkeit liegt im Wurzelverzeichnis neben public/.
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    $fail('vendor/autoload.php fehlt – bitte "composer install" ausführen');

    return;
}
require __DIR__ . '/../vendor/autoload.php';

Api::dispatch();
