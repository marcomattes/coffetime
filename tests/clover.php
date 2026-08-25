<?php

declare(strict_types=1);

/**
 * Merges the raw dumps written by tests/coverage.php into one Clover report.
 *
 * Clover is the interchange format SonarQube reads for PHP
 * (sonar.php.coverage.reportPaths). Writing it here keeps the suite
 * dependency-free: the project has no test framework to borrow a coverage
 * reporter from, and the mapping from Xdebug's line states to Clover is a
 * handful of lines.
 *
 * Usage: php tests/clover.php <dump-dir> <output.xml>
 */

/**
 * Merges every cov-*.json dump in $dir into one file => line => hits map.
 *
 * @return array<string, array<int, int>>
 */
function coffeeMergeCoverage(string $dir): array
{
    $merged = [];
    foreach (glob($dir . '/cov-*.json') ?: [] as $dump) {
        $decoded = json_decode((string) file_get_contents($dump), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $file => $lines) {
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line => $hits) {
                $line = (int) $line;
                $hits = (int) $hits;
                // A line the parent process never reached but a server worker
                // did is covered: the per-dump misses only win where nothing
                // else recorded a hit.
                $merged[$file][$line] = ($merged[$file][$line] ?? 0) + $hits;
            }
        }
    }
    foreach ($merged as $file => $lines) {
        ksort($lines);
        $merged[$file] = $lines;
    }
    ksort($merged);

    return $merged;
}

/**
 * Renders the merged map as Clover XML.
 *
 * @param array<string, array<int, int>> $coverage
 */
function coffeeWriteClover(array $coverage, string $outputPath, int $timestamp): void
{
    $projectStatements = 0;
    $projectCovered = 0;
    $projectLoc = 0;
    $files = '';

    foreach ($coverage as $file => $lines) {
        $statements = count($lines);
        $covered = count(array_filter($lines, static fn (int $hits): bool => $hits > 0));
        $loc = is_readable($file) ? count(file($file) ?: []) : $statements;

        $body = '';
        foreach ($lines as $line => $hits) {
            $body .= sprintf('      <line num="%d" type="stmt" count="%d"/>' . "\n", $line, $hits);
        }
        $files .= sprintf('    <file name="%s">' . "\n", htmlspecialchars($file, ENT_XML1 | ENT_QUOTES));
        $files .= $body;
        $files .= sprintf(
            '      <metrics loc="%d" ncloc="%d" statements="%d" coveredstatements="%d"'
            . ' conditionals="0" coveredconditionals="0" methods="0" coveredmethods="0"/>' . "\n",
            $loc,
            $loc,
            $statements,
            $covered
        );
        $files .= '    </file>' . "\n";

        $projectStatements += $statements;
        $projectCovered += $covered;
        $projectLoc += $loc;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . sprintf('<coverage generated="%d">' . "\n", $timestamp)
        . sprintf('  <project timestamp="%d">' . "\n", $timestamp)
        . $files
        . sprintf(
            '    <metrics files="%d" loc="%d" ncloc="%d" statements="%d" coveredstatements="%d"'
            . ' conditionals="0" coveredconditionals="0" methods="0" coveredmethods="0"/>' . "\n",
            count($coverage),
            $projectLoc,
            $projectLoc,
            $projectStatements,
            $projectCovered
        )
        . '  </project>' . "\n"
        . '</coverage>' . "\n";

    $dir = dirname($outputPath);
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create coverage directory: ' . $dir);
    }
    file_put_contents($outputPath, $xml);
}

/**
 * Percentage of recorded statements that were executed at least once.
 *
 * @param array<string, array<int, int>> $coverage
 */
function coffeeCoveragePercent(array $coverage): float
{
    $statements = 0;
    $covered = 0;
    foreach ($coverage as $lines) {
        $statements += count($lines);
        $covered += count(array_filter($lines, static fn (int $hits): bool => $hits > 0));
    }

    return $statements === 0 ? 0.0 : round($covered * 100 / $statements, 2);
}

// Only act as a script when invoked directly; tests/run.php includes this file
// for the functions above.
if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dumpDir = $argv[1] ?? '';
    $output = $argv[2] ?? '';
    if ($dumpDir === '' || $output === '') {
        fwrite(STDERR, "Usage: php tests/clover.php <dump-dir> <output.xml>\n");
        exit(1);
    }
    $coverage = coffeeMergeCoverage($dumpDir);
    if ($coverage === []) {
        fwrite(STDERR, "No coverage data found in {$dumpDir}\n");
        exit(1);
    }
    coffeeWriteClover($coverage, $output, time());
    printf("Wrote %s (%d files, %.2f%% lines)\n", $output, count($coverage), coffeeCoveragePercent($coverage));
}
