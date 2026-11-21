<?php

/*
 * Runs a single function-overhead case in its own process and prints the
 * result as one line of JSON. Use bench/overhead.php to run the whole matrix.
 *
 * Usage: php bench/overhead-case.php <case> <impl> <n>
 *
 *   <impl>  "native"   - the native iterator_* functions
 *           "polyfill" - the polyfill; when native functions exist, a copy
 *                        with the functions renamed to poly_iterator_* is
 *                        loaded, so both run on the same PHP binary
 *           "array"    - iterator_to_array() followed by the array_* function
 *   <n>     number of integers produced by the source
 */

[, $case, $impl, $n] = $argv + [null, null, null, 10_000_000];
$n = (int) $n;
$prefix = '';

if ($impl === 'native' && !function_exists('iterator_map')) {
    fwrite(STDERR, "This PHP binary has no native iterator_* functions\n");
    exit(1);
}
if ($impl === 'polyfill') {
    if (function_exists('iterator_map')) {
        $source = file_get_contents(__DIR__ . '/../src/functions.php');
        $renamed = preg_replace('/\biterator_(map|filter|slice|keys|values|reduce|any|all|find_key|find|first)\b/', 'poly_iterator_$1', $source);
        $file = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($file, $renamed);
        require $file;
        unlink($file);
        $prefix = 'poly_';
    } else {
        require __DIR__ . '/../src/functions.php';
    }
}

function numbers(int $n): Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}

$fn = static fn (string $name): string => $prefix . $name;
$consume = static function (iterable $it): int {
    $count = 0;
    foreach ($it as $_) {
        $count++;
    }
    return $count;
};
$isEven = static fn (int $x): bool => $x % 2 === 0;
$double = static fn (int $x): int => $x * 2;
$sum = static fn (int $carry, int $x): int => $carry + $x;
$isLast = static fn (int $x): bool => $x === $n - 1;
$list = static fn () => iterator_to_array(numbers($n), false);

// Every case reads from a generator, except array-source.
$cases = [
    'map' => [
        static fn () => $consume($fn('iterator_map')(numbers($n), $double)),
        static fn () => count(array_map($double, $list())),
    ],
    'filter' => [
        static fn () => $consume($fn('iterator_filter')(numbers($n), $isEven)),
        static fn () => count(array_filter($list(), $isEven)),
    ],
    'values' => [
        static fn () => $consume($fn('iterator_values')(numbers($n))),
        static fn () => count(array_values($list())),
    ],
    'slice' => [
        static fn () => $consume($fn('iterator_slice')(numbers($n), intdiv($n, 10) * 9, 1000)),
        static fn () => count(array_slice($list(), intdiv($n, 10) * 9, 1000)),
    ],
    'pipeline' => [
        static fn () => $fn('iterator_reduce')($fn('iterator_map')($fn('iterator_filter')(numbers($n), $isEven), $double), $sum, 0),
        static fn () => array_reduce(array_map($double, array_filter($list(), $isEven)), $sum, 0),
    ],
    'reduce' => [
        static fn () => $fn('iterator_reduce')(numbers($n), $sum, 0),
        static fn () => array_reduce($list(), $sum, 0),
    ],
    'find' => [
        static fn () => $fn('iterator_find')(numbers($n), $isLast),
        static fn () => array_find($list(), $isLast),
    ],
    'array-source' => [
        static fn () => $consume($fn('iterator_map')(range(0, $n - 1), $double)),
        static fn () => count(array_map($double, range(0, $n - 1))),
    ],
];

if (!isset($cases[$case]) || !in_array($impl, ['native', 'polyfill', 'array'], true)) {
    fwrite(STDERR, 'Cases: ' . implode(', ', array_keys($cases)) . "; implementations: native, polyfill, array\n");
    exit(1);
}

$run = $cases[$case][$impl === 'array' ? 1 : 0];
memory_reset_peak_usage();
$baseline = memory_get_usage();
$start = hrtime(true);
$result = $run();

echo json_encode([
    'seconds' => (hrtime(true) - $start) / 1e9,
    'peak_bytes' => memory_get_peak_usage() - $baseline,
    'result' => $result,
]), "\n";
