--TEST--
iterator_reduce(): same callback arguments as array_reduce()
--FILE--
<?php
require __DIR__ . '/../src/functions.php';

$gen = (function () {
    yield 'a' => 1;
    yield 'b' => 2;
    yield 'c' => 3;
})();

var_dump(iterator_reduce($gen, fn ($carry, $v) => $carry + $v, 10));
var_dump(iterator_reduce([], fn ($carry, $v) => $carry + $v));
var_dump(iterator_reduce(['x', 'y'], fn ($carry, $v) => $carry . $v, '>'));
?>
--EXPECT--
int(16)
NULL
string(3) ">xy"
