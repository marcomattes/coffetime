<?php

declare(strict_types=1);

/**
 * Runs every standalone tests/*Test.php script as its own child process,
 * streams its output, and aggregates the results. Exits non-zero if any
 * script fails (non-zero exit) or cannot be started at all.
 */

$dir = __DIR__;
$files = glob($dir . '/*Test.php') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "No test files matching tests/*Test.php were found.\n");
    exit(1);
}

$results = [];

foreach ($files as $file) {
    $name = basename($file);
    echo str_repeat('=', 70) . PHP_EOL;
    echo "Running {$name}" . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $file], $descriptors, $pipes, $dir);
    if (!is_resource($process)) {
        echo "FAIL could not start {$name}" . PHP_EOL;
        $results[$name] = false;
        continue;
    }
    fclose($pipes[0]);

    // Stream stdout/stderr live instead of buffering the whole run.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $open = [$pipes[1], $pipes[2]];
    while ($open !== []) {
        $read = $open;
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($stream)) {
                    fclose($stream);
                    $open = array_values(array_filter($open, static fn ($s) => $s !== $stream));
                }
                continue;
            }
            // Keep the child's stderr on our own stderr (and stdout on stdout)
            // rather than merging both into one stream, so output redirected
            // separately (e.g. `php tests/run.php 2>errors.log`) still splits
            // the same way it would running each test file directly.
            fwrite($stream === $pipes[2] ? STDERR : STDOUT, $chunk);
        }
    }

    $exitCode = proc_close($process);
    $results[$name] = $exitCode === 0;
    echo PHP_EOL;
}

echo str_repeat('=', 70) . PHP_EOL;
echo 'Summary' . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

$failed = 0;
foreach ($results as $name => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

printf("%d/%d test file(s) failed" . PHP_EOL, $failed, count($results));
exit($failed === 0 ? 0 : 1);
