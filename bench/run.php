<?php

/*
 * Runs every benchmark case for both variants, each in a fresh process, and
 * prints a Markdown table.
 *
 * Usage: php bench/run.php [--rows=1000000] [--php=/path/to/php ...]
 *
 * --rows  size of the generated NDJSON file (created in bench/data/ once and
 *         reused afterwards)
 * --php   PHP binary to benchmark with; may be repeated to compare builds,
 *         e.g. a release build of php-src with native iterator_* functions
 *         against a stock PHP running the polyfill. Defaults to the binary
 *         running this script.
 *
 * Requires PHP 8.4+ (array_find(), array_any()).
 */

$options = getopt('', ['rows:', 'php:']);
$rows = (int) ($options['rows'] ?? 1_000_000);
$binaries = (array) ($options['php'] ?? PHP_BINARY);

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir);
}
$file = "{$dataDir}/orders-{$rows}.ndjson";
if (!is_file($file)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate.php') . " {$rows} " . escapeshellarg($file), $status);
    if ($status !== 0) {
        exit($status);
    }
}
$fileMb = round(filesize($file) / 1_048_576);

$scenario = escapeshellarg(__DIR__ . '/scenario.php');
$runs = [
    // [label, case, arguments, extra ini, stdin from file]
    ["A. reduce over {$fileMb} MB file", 'reduce', $file, '', false],
    ["A. find 1000th order in {$fileMb} MB file", 'find', $file, '', false],
    ["B. reduce, memory_limit=64M", 'reduce', $file, '-d memory_limit=64M', false],
    ['C. find on page 3 of 1000 (paginated API)', 'api-find', null, '', false],
    ['C. any on page 3 of 1000 (paginated API)', 'api-any', null, '', false],
    ["D. reduce over STDIN ({$fileMb} MB)", 'reduce', '-', '', true],
];

echo "| Scenario | PHP | Variant | Time (s) | Peak memory | API requests |\n";
echo "|---|---|---|---:|---:|---:|\n";

foreach ($runs as [$label, $case, $argument, $ini, $useStdin]) {
    foreach ($binaries as $binary) {
        foreach (['array', 'iterator'] as $variant) {
            $command = escapeshellarg($binary) . " {$ini} {$scenario} {$case} {$variant}"
                . ($argument !== null ? ' ' . escapeshellarg($argument) : '')
                . ($useStdin ? ' < ' . escapeshellarg($file) : '')
                . ' 2>&1';
            $output = trim((string) shell_exec($command));
            $result = json_decode($output, true);

            if (!is_array($result)) {
                $error = str_contains($output, 'Allowed memory size') ? 'memory limit exceeded' : 'error: ' . strtok($output, "\n");
                $version = trim((string) shell_exec(escapeshellarg($binary) . ' -r "echo PHP_VERSION;"'));
                printf("| %s | %s | %s | — | %s | — |\n", $label, $version, $variant === 'array' ? 'array_*' : 'iterator_*', $error);
                continue;
            }

            printf(
                "| %s | %s | %s | %s | %s | %s |\n",
                $label,
                $result['php'],
                $result['implementation'] === 'array_*' ? 'array_*' : "iterator_* ({$result['implementation']})",
                $result['seconds'] < 0.001 ? '< 0.001' : sprintf('%.3f', $result['seconds']),
                format_bytes($result['peak_bytes']),
                $result['requests'] ?? '—',
            );
        }
    }
}

function format_bytes(int $bytes): string
{
    return $bytes >= 1_048_576
        ? sprintf('%.1f MB', $bytes / 1_048_576)
        : sprintf('%.1f KB', $bytes / 1024);
}
