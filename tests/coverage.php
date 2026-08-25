<?php

declare(strict_types=1);

/**
 * Line-coverage collector for the framework-free test suite.
 *
 * tests/run.php injects this file as auto_prepend_file into every process it
 * starts when it is run with --coverage: the test scripts themselves and the
 * `php -S` workers that tests/helpers.php spins up for the HTTP tests. Doing
 * it through the ini setting rather than a require keeps the test files
 * themselves free of coverage plumbing, and covers the built-in server, where
 * the prepend runs once per request.
 *
 * Each process (for the built-in server: each request) writes its raw Xdebug
 * data as JSON into COFFEE_COVERAGE_DIR; tests/clover.php merges those dumps
 * into the Clover report SonarQube reads. Nothing here runs unless that
 * variable is set, so a normal `php tests/run.php` is unaffected even if the
 * file is prepended by accident.
 */

if (!function_exists('coffeeCoverageCliArgs')) {
    /**
     * Extra CLI arguments that put a child PHP process into coverage mode.
     * Empty unless the parent itself was started with coverage enabled, which
     * is what keeps the ordinary test run free of Xdebug.
     *
     * @return list<string>
     */
    function coffeeCoverageCliArgs(): array
    {
        if ((string) getenv('COFFEE_COVERAGE_DIR') === '') {
            return [];
        }

        return [
            '-d',
            'xdebug.mode=coverage',
            '-d',
            'auto_prepend_file=' . __DIR__ . '/coverage.php',
        ];
    }
}

if (!function_exists('coffeeFilterCoverage')) {
    /**
     * Reduces one raw xdebug_get_code_coverage() result to the dump format.
     *
     * Xdebug reports 1 (or more) for an executed line, -1 for an executable
     * line that never ran and -2 for a line it proved unreachable. Dead code
     * is dropped rather than reported as a miss – it is not a coverage gap and
     * counting it would put a permanent ceiling on the percentage. Everything
     * outside the application's own PHP is dropped as well: the tests, the
     * harness and vendor/ are not what the report is about.
     *
     * @param array<string, array<int, int>> $raw
     * @return array<string, array<string, int>>
     */
    function coffeeFilterCoverage(array $raw, string $root): array
    {
        $data = [];
        foreach ($raw as $file => $lines) {
            $isApplicationCode = str_starts_with($file, $root . '/src/')
                || str_starts_with($file, $root . '/public/');
            if (!$isApplicationCode || !str_ends_with($file, '.php')) {
                continue;
            }
            $counts = [];
            foreach ($lines as $line => $state) {
                if ($state === -2) {
                    continue;
                }
                $counts[(string) $line] = $state >= 1 ? $state : 0;
            }
            if ($counts !== []) {
                $data[$file] = $counts;
            }
        }

        return $data;
    }
}

(static function (): void {
    $dir = (string) getenv('COFFEE_COVERAGE_DIR');
    if ($dir === '' || !function_exists('xdebug_start_code_coverage')) {
        return;
    }
    // Only the processes started with -d xdebug.mode=coverage collect. The
    // parent runner requires this file for coffeeCoverageCliArgs() alone, and
    // must not trip over a coverage call Xdebug would only warn about.
    if (!str_contains((string) ini_get('xdebug.mode'), 'coverage')) {
        return;
    }

    // XDEBUG_CC_UNUSED reports executable lines that never ran (without it an
    // untested file is simply absent, which reads as 100% covered);
    // XDEBUG_CC_DEAD_CODE marks the unreachable ones coffeeFilterCoverage()
    // then drops.
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

    register_shutdown_function(static function () use ($dir): void {
        $raw = xdebug_get_code_coverage();
        xdebug_stop_code_coverage();

        $data = coffeeFilterCoverage($raw, dirname(__DIR__));
        if ($data === []) {
            return;
        }

        // One file per process (per request, under the built-in server), named
        // uniquely so that concurrent server workers cannot overwrite each
        // other's dump.
        $name = sprintf('%s/cov-%d-%s.json', $dir, getmypid(), bin2hex(random_bytes(8)));
        file_put_contents($name, json_encode($data, JSON_THROW_ON_ERROR));
    });
})();
