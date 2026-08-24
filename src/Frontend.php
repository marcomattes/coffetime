<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Serves the application shell from `src/shell.html`.
 *
 * The markup lives in that template file, not in PHP: the shell is static
 * apart from the build id, and keeping it as plain HTML keeps this class
 * free of markup. `src/` is never served over HTTP (its own .htaccess
 * denies it), so the template only ever reaches the browser through here.
 *
 * The shell contains no user data. JavaScript fetches all dynamic content
 * from the API and inserts it using `textContent`.
 */
final class Frontend
{
    public static function shell(): string
    {
        $build = htmlspecialchars(Version::current()['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace('{{BUILD}}', $build, self::template());
    }

    private static function template(): string
    {
        $path = __DIR__ . '/shell.html';
        $markup = @file_get_contents($path);
        if ($markup === false || $markup === '') {
            // Deployment error (the bundle ships src/ as a whole). The
            // front controller turns this into a JSON 500 without leaking
            // the path to the client.
            throw new \RuntimeException('missing or empty shell template: ' . $path);
        }

        return $markup;
    }
}
