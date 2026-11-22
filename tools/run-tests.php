<?php

declare(strict_types=1);

/*
 * Minimal runner for .phpt files (subset of php-src's run-tests.php):
 * supports the --TEST--, --SKIPIF--, --FILE-- and --EXPECT-- sections.
 * --SKIPIF-- may print only "skip <reason>"; any other output fails the test
 * (php-src's info/warn/xfail prefixes are not supported).
 *
 * The tests use the php-src format so they can later be moved into php-src
 * with only the `require` line removed.
 *
 * Usage: php tools/run-tests.php [tests-dir]
 */

$dir = $argv[1] ?? __DIR__ . '/../tests';
$files = glob(rtrim($dir, '/') . '/*.phpt') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "No .phpt files found in {$dir}\n");
    exit(1);
}

$passed = 0;
$skipped = 0;
$failed = [];

foreach ($files as $file) {
    $sections = parse_phpt((string) file_get_contents($file));
    foreach (['TEST', 'FILE', 'EXPECT'] as $required) {
        if (!isset($sections[$required])) {
            $failed[] = [$file, "missing --{$required}-- section", ''];
            continue 2;
        }
    }

    if (isset($sections['SKIPIF'])) {
        $reason = trim(run_php($file, $sections['SKIPIF']));
        if (stripos($reason, 'skip') === 0) {
            ++$skipped;
            echo 'SKIP ', trim($sections['TEST']), ' [', basename($file), '] ', trim(substr($reason, 4)), "\n";
            continue;
        }
        if ($reason !== '') {
            // Like php-src's BORK: a broken prerequisite check must not let the test pass.
            $failed[] = [$file, 'no output from --SKIPIF--', $reason];
            echo 'FAIL ', trim($sections['TEST']), ' [', basename($file), "] invalid output from --SKIPIF--\n";
            continue;
        }
    }

    $output = run_php($file, $sections['FILE']);

    $expected = normalize($sections['EXPECT']);
    $actual = normalize($output);

    if ($expected === $actual) {
        ++$passed;
        echo 'PASS ', trim($sections['TEST']), ' [', basename($file), "]\n";
    } else {
        $failed[] = [$file, $expected, $actual];
        echo 'FAIL ', trim($sections['TEST']), ' [', basename($file), "]\n";
    }
}

foreach ($failed as [$file, $expected, $actual]) {
    echo "\n=== ", basename($file), " ===\n--- expected\n{$expected}\n--- actual\n{$actual}\n";
}

printf("\n%d passed, %d skipped, %d failed\n", $passed, $skipped, count($failed));
exit($failed === [] ? 0 : 1);

function run_php(string $file, string $code): string
{
    $script = preg_replace('/\.phpt$/', '.tmp.php', $file);
    file_put_contents($script, $code);

    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 '
        . escapeshellarg($script) . ' 2>&1';
    $output = (string) shell_exec($cmd);
    unlink($script);

    return $output;
}

/** @return array<string, string> */
function parse_phpt(string $content): array
{
    $sections = [];
    $current = null;
    foreach (preg_split('/\R/', $content) as $line) {
        if (preg_match('/^--([A-Z_]+)--$/', $line, $m)) {
            $current = $m[1];
            $sections[$current] = '';
            continue;
        }
        if ($current !== null) {
            $sections[$current] .= $line . "\n";
        }
    }

    return $sections;
}

function normalize(string $text): string
{
    return trim(str_replace("\r\n", "\n", $text));
}
