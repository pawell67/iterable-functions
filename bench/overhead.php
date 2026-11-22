<?php

/*
 * Measures the overhead of the functions themselves: every case reads
 * integers from a generator, so nothing but the function under test does any
 * work. Each run is a fresh process; the table shows the median.
 *
 * Usage: php bench/overhead.php [--n=10000000] [--runs=5] [--php=/path/to/php]
 *
 * --php  binary to run the cases with (defaults to the one running this
 *        script). With native iterator_* functions, the polyfill is measured
 *        on the same binary under renamed functions, so the comparison is not
 *        skewed by differences between builds.
 *
 * The array_* variant materializes the input and needs a lot of memory
 * (about 1 GB for the pipeline case with the default --n), so all cases run
 * with memory_limit=-1. Requires PHP 8.4+.
 */

$options = getopt('', ['n:', 'runs:', 'php:']);
$n = (int) ($options['n'] ?? 10_000_000);
$runs = max(1, (int) ($options['runs'] ?? 5));
$binary = $options['php'] ?? PHP_BINARY;

$case = escapeshellarg(__DIR__ . '/overhead-case.php');
$php = escapeshellarg($binary) . ' -d memory_limit=-1';
$hasNative = trim((string) shell_exec("{$php} -r " . escapeshellarg('echo (int) function_exists("iterator_map");'))) === '1';
$version = trim((string) shell_exec("{$php} -r " . escapeshellarg('echo PHP_VERSION, PHP_DEBUG ? " (debug)" : "";')));
$implementations = $hasNative ? ['native', 'polyfill', 'array'] : ['polyfill', 'array'];

printf("PHP %s, n = %s, median of %d runs\n\n", $version, number_format($n), $runs);
$header = array_map(static fn ($i) => $i === 'array' ? 'array_*' : $i, $implementations);
echo '| Case | ', implode(' | ', $header), ' |', $hasNative ? ' polyfill / native |' : '', ' Peak memory (', implode(', ', $header), ") |\n";
echo '|---|', str_repeat('---:|', count($implementations) + ($hasNative ? 1 : 0)), "---|\n";

foreach (['map', 'filter', 'values', 'slice', 'pipeline', 'reduce', 'find', 'array-source'] as $name) {
    $times = $peaks = $results = [];
    foreach ($implementations as $impl) {
        $samples = [];
        for ($i = 0; $i < $runs; $i++) {
            $output = trim((string) shell_exec("{$php} {$case} {$name} {$impl} {$n} 2>&1"));
            $result = json_decode($output, true);
            if (!is_array($result)) {
                fwrite(STDERR, "{$name}/{$impl} failed: {$output}\n");
                exit(1);
            }
            $samples[] = $result['seconds'];
            $peaks[$impl] = max($peaks[$impl] ?? 0, $result['peak_bytes']);
            $results[json_encode($result['result'])] = true;
        }
        sort($samples);
        $times[$impl] = $samples[intdiv($runs, 2)];
    }
    if (count($results) !== 1) {
        fwrite(STDERR, "{$name}: implementations returned different results\n");
        exit(1);
    }
    printf(
        "| %s | %s |%s %s |\n",
        $name,
        implode(' | ', array_map(static fn ($t) => sprintf('%.3f s', $t), $times)),
        $hasNative ? sprintf(' %.2f× |', $times['polyfill'] / $times['native']) : '',
        implode(', ', array_map(format_bytes(...), $peaks)),
    );
}

function format_bytes(int $bytes): string
{
    return $bytes >= 1_048_576
        ? sprintf('%.1f MB', $bytes / 1_048_576)
        : sprintf('%.1f KB', $bytes / 1024);
}
