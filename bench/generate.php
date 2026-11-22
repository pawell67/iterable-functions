<?php

/*
 * Generates a deterministic NDJSON file of synthetic orders, one JSON object
 * per line:
 *
 *   {"id":1,"user_id":4821,"amount":129.99,"status":"paid","created_at":"2026-01-01T00:00:07+00:00"}
 *
 * The same seed and row count always produce the same file, so benchmark
 * results can be reproduced on any machine.
 *
 * Usage: php bench/generate.php <rows> <output-file> [seed]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} <rows> <output-file> [seed]\n");
    exit(1);
}

$rows = (int) $argv[1];
$path = $argv[2];
$seed = (int) ($argv[3] ?? 42);

if ($rows < 1) {
    fwrite(STDERR, "<rows> must be a positive integer\n");
    exit(1);
}

$random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
$statuses = ['paid', 'paid', 'paid', 'pending', 'refunded'];
$time = (new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->getTimestamp();

$out = fopen($path, 'wb');
if ($out === false) {
    fwrite(STDERR, "Cannot open {$path} for writing\n");
    exit(1);
}

$buffer = '';
for ($id = 1; $id <= $rows; $id++) {
    $time += $random->getInt(1, 30);
    $buffer .= json_encode([
        'id' => $id,
        'user_id' => $random->getInt(1, 100_000),
        'amount' => $random->getInt(100, 99_999) / 100,
        'status' => $statuses[$random->getInt(0, count($statuses) - 1)],
        'created_at' => gmdate('Y-m-d\TH:i:sP', $time),
    ]) . "\n";

    if ($id % 10_000 === 0) {
        fwrite($out, $buffer);
        $buffer = '';
    }
}
fwrite($out, $buffer);
fclose($out);

printf("Wrote %d rows (%.1f MB) to %s\n", $rows, filesize($path) / 1_048_576, $path);
