<?php

declare(strict_types=1);

/**
 * Tests for the coverage plumbing itself (tests/coverage.php and
 * tests/clover.php).
 *
 * The collector only runs under Xdebug, which the ordinary test run does not
 * load, so the parts that shape and merge the data are checked here against
 * hand-written input instead. That keeps a broken report from reaching
 * SonarQube as a silent drop in coverage rather than a failing build.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/coverage.php';
require_once __DIR__ . '/clover.php';

$root = dirname(__DIR__);

// ---------------------------------------------------------------- filtering ---

$raw = [
    $root . '/src/Crypto.php' => [10 => 1, 11 => -1, 12 => -2, 13 => 5],
    $root . '/public/index.php' => [3 => 1],
    $root . '/tests/UnitTest.php' => [7 => 1],
    $root . '/vendor/some/lib/Thing.php' => [7 => 1],
    $root . '/src/shell.html' => [1 => 1],
];
$filtered = coffeeFilterCoverage($raw, $root);

check('application code is kept', isset($filtered[$root . '/src/Crypto.php']));
check('the document root is kept', isset($filtered[$root . '/public/index.php']));
check('the tests themselves are dropped', !isset($filtered[$root . '/tests/UnitTest.php']));
check('vendor code is dropped', !isset($filtered[$root . '/vendor/some/lib/Thing.php']));
check('non-PHP files are dropped', !isset($filtered[$root . '/src/shell.html']));

$crypto = $filtered[$root . '/src/Crypto.php'] ?? [];
check('an executed line keeps its hit count', ($crypto['10'] ?? null) === 1);
check('a repeatedly executed line keeps its count', ($crypto['13'] ?? null) === 5);
// -1 is "executable but never reached": the miss the report exists to show.
check('an unexecuted line is reported as a miss', ($crypto['11'] ?? null) === 0);
// -2 is code the optimiser proved unreachable. Counted as a miss it would put
// a permanent ceiling on the percentage, so it is left out entirely.
check('dead code is left out rather than counted as a miss', !array_key_exists('12', $crypto));

// ------------------------------------------------------------------ merging ---

$dumpDir = sys_get_temp_dir() . '/coffee-coverage-test-' . bin2hex(random_bytes(6));
mkdir($dumpDir, 0o775, true);
file_put_contents($dumpDir . '/cov-1-aaaa.json', json_encode([
    $root . '/src/Crypto.php' => ['10' => 1, '11' => 0, '12' => 0],
    $root . '/src/Db.php' => ['5' => 2],
], JSON_THROW_ON_ERROR));
file_put_contents($dumpDir . '/cov-2-bbbb.json', json_encode([
    $root . '/src/Crypto.php' => ['10' => 3, '11' => 1, '12' => 0],
], JSON_THROW_ON_ERROR));
// Anything that is not a dump must not derail the merge.
file_put_contents($dumpDir . '/cov-3-cccc.json', 'not json');

$merged = coffeeMergeCoverage($dumpDir);
check('dumps from every process are merged', count($merged) === 2);
check('hit counts add up across processes', ($merged[$root . '/src/Crypto.php'][10] ?? null) === 4);
// The decisive case for the built-in server: a line the test script never
// reached but a server worker did is covered, not missed.
check(
    'a line covered by only one process counts as covered',
    ($merged[$root . '/src/Crypto.php'][11] ?? null) === 1
);
check(
    'a line no process reached stays a miss',
    ($merged[$root . '/src/Crypto.php'][12] ?? null) === 0
);
check('an unreadable dump is skipped instead of aborting the merge', isset($merged[$root . '/src/Db.php']));
check('coverage percentage counts executed lines only', coffeeCoveragePercent($merged) === 75.0);

// ------------------------------------------------------------------- Clover ---

$reportPath = $dumpDir . '/clover.xml';
coffeeWriteClover($merged, $reportPath, 1700000000);
$xml = new DOMDocument();
check('the report is well-formed XML', $xml->load($reportPath));

$xpath = new DOMXPath($xml);
$files = $xpath->query('/coverage/project/file');
check('every covered file appears once', $files !== false && $files->length === 2);

// Sonar matches report entries to its own files by this path, so an entry it
// cannot resolve is coverage that silently never arrives.
$names = [];
foreach ($xpath->query('/coverage/project/file/@name') ?: [] as $attribute) {
    $names[] = $attribute->nodeValue;
}
check('files are named by absolute path', in_array($root . '/src/Crypto.php', $names, true));

$covered = $xpath->query('/coverage/project/file[@name="' . $root . '/src/Crypto.php"]/line[@count>0]');
check('covered lines carry a non-zero count', $covered !== false && $covered->length === 2);
$missed = $xpath->query('/coverage/project/file[@name="' . $root . '/src/Crypto.php"]/line[@count=0]');
check('missed lines are reported with count 0', $missed !== false && $missed->length === 1);
$types = $xpath->query('/coverage/project/file/line[@type="stmt"]');
check('lines are typed as statements', $types !== false && $types->length === 4);

$projectMetrics = $xpath->query('/coverage/project/metrics')->item(0);
check(
    'project metrics total the statements',
    $projectMetrics instanceof DOMElement && $projectMetrics->getAttribute('statements') === '4'
);
check(
    'project metrics total the covered statements',
    $projectMetrics instanceof DOMElement && $projectMetrics->getAttribute('coveredstatements') === '3'
);

array_map('unlink', glob($dumpDir . '/*') ?: []);
rmdir($dumpDir);

// ----------------------------------------------------------------- CLI args ---

// Without the environment variable no child may be switched into coverage
// mode: an ordinary `php tests/run.php` must not pay for Xdebug.
putenv('COFFEE_COVERAGE_DIR');
check('no coverage arguments without the environment variable', coffeeCoverageCliArgs() === []);
putenv('COFFEE_COVERAGE_DIR=' . sys_get_temp_dir());
$args = coffeeCoverageCliArgs();
check('coverage mode is passed to children', in_array('xdebug.mode=coverage', $args, true));
check(
    'the collector is prepended in children',
    in_array('auto_prepend_file=' . __DIR__ . '/coverage.php', $args, true)
);
putenv('COFFEE_COVERAGE_DIR');

summarizeAndExit();
