--TEST--
iterator_find(): like array_find(), internal callables reject the extra key argument
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip array_find() requires PHP 8.4'); ?>
--FILE--
<?php
require __DIR__ . '/../src/functions.php';

foreach (['array_find', 'iterator_find'] as $fn) {
    try {
        $fn(['abc'], strlen(...));
    } catch (ArgumentCountError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
array_find: strlen() expects exactly 1 argument, 2 given
iterator_find: strlen() expects exactly 1 argument, 2 given
