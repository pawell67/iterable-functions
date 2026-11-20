<?php

/*
 * Runs a single benchmark case in its own process and prints the result as
 * one line of JSON. Use bench/run.php to run the whole matrix.
 *
 * Usage: php bench/scenario.php <case> <variant> [file|-]
 *
 *   <case>     one of the keys of $cases below
 *   <variant>  "array"    - iterator_to_array() followed by the array_* function
 *              "iterator" - the iterator_* function (native if available,
 *                           otherwise the polyfill)
 *   [file|-]   NDJSON file produced by bench/generate.php, or "-" for STDIN
 *              (not used by the api-* cases)
 */

$native = function_exists('iterator_find');
require __DIR__ . '/../src/functions.php';

[, $case, $variant] = $argv + [null, null, null];
$source = $argv[3] ?? null;

/** Reads NDJSON orders lazily, one line at a time. */
function orders(string $source): Generator
{
    $handle = $source === '-' ? STDIN : fopen($source, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open {$source}");
    }
    while (($line = fgets($handle)) !== false) {
        yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}

/**
 * Simulates a paginated HTTP API: every page costs one "request" (and a short
 * sleep standing in for network latency).
 */
function api_items(int $pages, int $perPage, int &$requests): Generator
{
    for ($page = 1; $page <= $pages; $page++) {
        $requests++;
        usleep(200);
        for ($i = 1; $i <= $perPage; $i++) {
            $id = ($page - 1) * $perPage + $i;
            yield ['id' => $id, 'flagged' => $id === 250];
        }
    }
}

$requests = 0;
$isPaid = static fn (array $o) => $o['status'] === 'paid';
$sumPaid = static fn (float $carry, array $o) => $o['status'] === 'paid' ? $carry + $o['amount'] : $carry;

$cases = [
    // Full pass over the input: both variants read everything, the difference
    // is whether the whole input is held in memory at once.
    'reduce' => [
        'array' => static fn () => array_reduce(iterator_to_array(orders($source), false), $sumPaid, 0.0),
        'iterator' => static fn () => iterator_reduce(orders($source), $sumPaid, 0.0),
    ],
    // Early exit: the match is the 1000th order.
    'find' => [
        'array' => static fn () => array_find(iterator_to_array(orders($source), false), static fn (array $o) => $o['id'] === 1000)['id'],
        'iterator' => static fn () => iterator_find(orders($source), static fn (array $o) => $o['id'] === 1000)['id'],
    ],
    // Early exit on an expensive source: the match is on page 3 of 1000.
    // Regular closures (not arrow functions), so $requests is shared by reference.
    'api-find' => [
        'array' => static function () use (&$requests) {
            return array_find(iterator_to_array(api_items(1000, 100, $requests), false), static fn (array $i) => $i['flagged'])['id'];
        },
        'iterator' => static function () use (&$requests) {
            return iterator_find(api_items(1000, 100, $requests), static fn (array $i) => $i['flagged'])['id'];
        },
    ],
    'api-any' => [
        'array' => static function () use (&$requests) {
            return array_any(iterator_to_array(api_items(1000, 100, $requests), false), static fn (array $i) => $i['flagged']);
        },
        'iterator' => static function () use (&$requests) {
            return iterator_any(api_items(1000, 100, $requests), static fn (array $i) => $i['flagged']);
        },
    ],
];

if (!isset($cases[$case][$variant])) {
    fwrite(STDERR, "Unknown case/variant. Cases: " . implode(', ', array_keys($cases)) . "; variants: array, iterator\n");
    exit(1);
}
if ($source === null && !str_starts_with($case, 'api-')) {
    fwrite(STDERR, "Case {$case} needs an NDJSON file or '-' for STDIN\n");
    exit(1);
}

memory_reset_peak_usage();
$baseline = memory_get_usage();
$start = hrtime(true);

$result = $cases[$case][$variant]();

echo json_encode([
    'case' => $case,
    'variant' => $variant,
    'implementation' => $variant === 'array' ? 'array_*' : ($native ? 'native' : 'polyfill'),
    'php' => PHP_VERSION,
    'result' => $result,
    'seconds' => (hrtime(true) - $start) / 1e9,
    'peak_bytes' => memory_get_peak_usage() - $baseline,
    'requests' => str_starts_with($case, 'api-') ? $requests : null,
]), "\n";
