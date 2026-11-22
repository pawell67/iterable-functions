--TEST--
iterator_any() / iterator_all(): short-circuit, callback receives ($value, $key)
--FILE--
<?php
require __DIR__ . '/../src/functions.php';

$naturals = function () {
    for ($i = 1; ; $i++) {
        echo "pull $i\n";
        yield "n$i" => $i;
    }
};

var_dump(iterator_any($naturals(), fn ($v) => $v === 3));
var_dump(iterator_all($naturals(), fn ($v) => $v < 2));

echo "-- key is passed as the second argument\n";
var_dump(iterator_any(['a' => 1, 'b' => 2], fn ($v, $k) => $k === 'b'));
var_dump(iterator_all(['a' => 1, 'b' => 2], fn ($v, $k) => is_string($k)));

echo "-- empty input\n";
var_dump(iterator_any([], fn () => true));
var_dump(iterator_all([], fn () => false));
?>
--EXPECT--
pull 1
pull 2
pull 3
bool(true)
pull 1
pull 2
bool(false)
-- key is passed as the second argument
bool(true)
bool(true)
-- empty input
bool(false)
bool(true)
