# iterable-functions

Userland polyfill for a proposed set of **`iterator_*` functions: lazy
counterparts of existing `array_*` functions that accept any iterable**
(`array|Traversable`). It is intended as a reference implementation for a PHP
internals pre-RFC.

> **Status: experimental.** The API follows a proposal that has not been
> discussed on php-internals yet and may change.

## Why

`array_*` functions accept only arrays. Code working with generators, database
cursors or other `Traversable`s must call `iterator_to_array()` first, which:

- materializes the whole sequence in memory,
- gives up early exit (`array_find()`/`array_any()` could stop at the first match),
- never terminates on infinite sources, and runs every expensive fetch
  (paginated APIs, queues) up front,
- with the default `$preserve_keys = true`, silently drops values when keys
  repeat.

Simply widening `array_map()` and friends to accept iterables would not help,
because they would still have to consume the whole input to return an array.
Lazy variants have to return an iterator instead.

The pipe operator (PHP 8.5) and partial function application (PHP 8.6) make
this gap more visible and give these functions a natural way to compose.

## Installation

```bash
composer require pawell67/iterable-functions
```

Requires PHP 8.2+. Every function (and the `ARRAY_FILTER_USE_VALUE` constant,
native since PHP 8.6) is declared only if it does not exist yet, so the package
steps aside if a native implementation ships.

## API

The set is deliberately closed: **every function is the iterable counterpart of
an existing `array_*` function**, with the same callback arguments. The RFC
therefore introduces no new concepts.

| `array_*` | `iterator_*` | Kind | Callback receives |
|---|---|---|---|
| `array_map` | `iterator_map(iterable $iterator, callable $callback): Iterator` | lazy | `$value` |
| `array_filter` | `iterator_filter(iterable $iterator, ?callable $callback = null, int $mode = ARRAY_FILTER_USE_VALUE): Iterator` | lazy | depends on `$mode`, as in `array_filter()` |
| `array_slice` | `iterator_slice(iterable $iterator, int $offset, ?int $length = null): Iterator` | lazy | — |
| `array_keys` | `iterator_keys(iterable $iterator): Iterator` | lazy | — |
| `array_values` | `iterator_values(iterable $iterator): Iterator` | lazy | — |
| `array_reduce` | `iterator_reduce(iterable $iterator, callable $callback, mixed $initial = null): mixed` | terminal | `$carry, $value` |
| `array_any` | `iterator_any(iterable $iterator, callable $callback): bool` | terminal, short-circuits | `$value, $key` |
| `array_all` | `iterator_all(iterable $iterator, callable $callback): bool` | terminal, short-circuits | `$value, $key` |
| `array_find` | `iterator_find(iterable $iterator, callable $callback): mixed` | terminal, short-circuits | `$value, $key` |
| `array_find_key` | `iterator_find_key(iterable $iterator, callable $callback): mixed` | terminal, short-circuits | `$value, $key` |
| `array_first` | `iterator_first(iterable $iterator): mixed` | terminal, pulls at most one entry | — |

Lazy functions return a non-rewindable `Iterator` and never pull more entries
from the input than needed.

To materialize a result, use the existing `iterator_to_array()`:
`iterator_to_array($it, false)` returns a list and never loses values to
duplicate keys.

## Design decisions

1. **The `iterator_*` prefix, not a namespace.** `iterator_to_array()`,
   `iterator_count()` and `iterator_apply()` already form a family, and since
   PHP 8.2 the first two accept any iterable. The
   [Namespaces in bundled extensions](https://wiki.php.net/rfc/namespaces_in_bundled_extensions)
   RFC says that when adding to an existing extension, consistency with its
   existing symbols takes precedence over namespacing (its example:
   `array_is_list()`, not `Array\is_list()`). The prefix also cannot collide
   with [nikic/iter](https://github.com/nikic/iter)'s `iter\` namespace.
2. **The iterable is always the first parameter**, the callback (if any)
   last. This matches `array_filter()`, `array_reduce()`, `array_find()` and
   `iterator_apply()`, and composes with partial function application:
   `$x |> iterator_map(?, $fn)`. `array_map()` takes its arguments in the
   opposite order (`$callback, $array`). `iterator_map()` deliberately does
   not follow it, so that the whole set stays consistent.
3. **Callbacks receive the same arguments as in the `array_*` counterpart.**
   `iterator_map()` and `iterator_filter()` (by default) pass the value only.
   `iterator_any()`, `iterator_all()`, `iterator_find()` and
   `iterator_find_key()` pass `$value, $key`, like their PHP 8.4 counterparts.
   As with `array_find()`, internal functions that reject extra arguments
   (e.g. `strlen(...)`) therefore cannot be passed directly to those four.
4. **`iterator_filter()` supports `$mode`** with the existing
   `ARRAY_FILTER_USE_VALUE`, `ARRAY_FILTER_USE_KEY` and
   `ARRAY_FILTER_USE_BOTH` constants. Reusing them keeps the function a
   drop-in counterpart of `array_filter()` and avoids a parallel set of
   constants.
5. **Lazy functions preserve keys**, like `array_filter()`.
   `iterator_keys()` and `iterator_values()` produce lists.
6. **`iterator_slice()` differs from `array_slice()` in two ways:**
   - Keys are always preserved: there is no `$preserve_keys` parameter, and
     integer keys are not renumbered. Use `iterator_values()` to get a list.
   - Negative `$offset`/`$length` throw a `ValueError`, because supporting
     them would require buffering the whole input.
7. **Arguments are validated at call time**, not on first iteration, as in a
   native implementation. For example, `iterator_slice($it, -1)` throws
   immediately.
8. **Callbacks use coercive typing**, like callbacks invoked by internal
   functions. The polyfill does not declare `strict_types`, so
   `iterator_map([123], strlen(...))` yields `3`, as `array_map()` does.
9. **No changes to existing `array_*` or `iterator_*` functions.**

### Caveat: `null` results

As with `array_find()`, `iterator_find()` returns `null` both when nothing
matches and when the matching value is `null`. Iterator keys, unlike array
keys, may themselves be `null` (`yield null => $v`), so the same ambiguity
applies to `iterator_find_key()`.

## Future scope

These are out of scope for the proposal and may be considered separately:
`chunk`, `zip`, `chain`, `take_while`/`skip_while`, `contains`, `none`, the
`$filter_value`/`$strict` parameters of `array_keys()`, and key-aware variants
of `iterator_map()`. `iterator_last()` is excluded on purpose: it would have
to consume the whole input and never terminates on infinite sources.

## Example

```php
// PHP 8.6 (pipe operator + partial function application)
$total = $orders
    |> iterator_filter(?, fn (Order $o) => $o->paid)
    |> iterator_map(?, fn (Order $o) => $o->amount)
    |> iterator_slice(?, 0, 100)
    |> iterator_reduce(?, fn ($sum, $x) => $sum + $x, 0);

// PHP 8.2+
$total = iterator_reduce(
    iterator_slice(
        iterator_map(
            iterator_filter($orders, fn (Order $o) => $o->paid),
            fn (Order $o) => $o->amount,
        ),
        0,
        100,
    ),
    fn ($sum, $x) => $sum + $x,
    0,
);
```

## Tests

Tests use the php-src `.phpt` format, so they can later be moved into php-src
with only the `require` line removed. The bundled runner supports the
`--TEST--`, `--SKIPIF--`, `--FILE--` and `--EXPECT--` sections:

```bash
php tools/run-tests.php   # or: composer test
```

## Benchmarks

`bench/` compares `iterator_to_array()` + `array_*` with `iterator_*` on a
large NDJSON file (also read from STDIN), under a low `memory_limit`, and on a
simulated paginated API where early exit saves requests. Each case runs in a
fresh process. Requires PHP 8.4+.

```bash
php bench/run.php --rows=1000000                      # generates bench/data/ once
php bench/run.php --php=php8.5 --php=/path/to/php-src/sapi/cli/php   # compare builds
```

The data is synthetic and generated from a fixed seed, so results can be
reproduced on any machine. Use release (non-debug) builds when comparing
timings.

## License

MIT
