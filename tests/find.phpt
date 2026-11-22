--TEST--
iterator_find() / iterator_find_key(): short-circuit, callback receives ($value, $key)
--FILE--
<?php
require __DIR__ . '/../src/functions.php';

$naturals = function () {
    for ($i = 1; ; $i++) {
        echo "pull $i\n";
        yield "n$i" => $i;
    }
};

var_dump(iterator_find($naturals(), fn ($v) => $v % 2 === 0));
var_dump(iterator_find_key($naturals(), fn ($v) => $v % 2 === 0));

echo "-- key is passed as the second argument\n";
var_dump(iterator_find(['a' => 1, 'b' => 2], fn ($v, $k) => $k === 'b'));
var_dump(iterator_find_key(['a' => 1, 'b' => 2], fn ($v, $k) => $v === 1));

echo "-- not found\n";
var_dump(iterator_find([1, 2], fn ($v) => $v > 5));
var_dump(iterator_find_key([1, 2], fn ($v) => $v > 5));
?>
--EXPECT--
pull 1
pull 2
int(2)
pull 1
pull 2
string(2) "n2"
-- key is passed as the second argument
int(2)
string(1) "a"
-- not found
NULL
NULL
