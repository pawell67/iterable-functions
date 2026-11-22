--TEST--
Loading the polyfill twice (or next to a native implementation) does not redeclare functions or constants
--FILE--
<?php
require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/functions.php';

var_dump(ARRAY_FILTER_USE_VALUE);
echo "ok\n";
?>
--EXPECT--
int(0)
ok
