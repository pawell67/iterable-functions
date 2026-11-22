--TEST--
iterator_first(): pulls at most one entry, null when empty
--FILE--
<?php
require __DIR__ . '/../src/functions.php';

$naturals = (function () {
    for ($i = 1; ; $i++) {
        echo "pull $i\n";
        yield "n$i" => $i;
    }
})();

var_dump(iterator_first($naturals));
var_dump(iterator_first([]));
var_dump(iterator_first(['x' => 'a', 'y' => 'b']));
var_dump(iterator_first(new ArrayObject([7, 8])));
?>
--EXPECT--
pull 1
int(1)
NULL
string(1) "a"
int(7)
