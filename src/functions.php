<?php

/*
 * Userland polyfill for a proposed set of iterator_* functions: lazy
 * counterparts of existing array_* functions that accept any iterable
 * (array|Traversable).
 *
 * Conventions shared by every function:
 *  - the iterable is always the first parameter, the callback (if any) last;
 *  - each callback receives the same arguments as in the array_* counterpart:
 *    iterator_map() and iterator_filter() (by default) pass the value only,
 *    iterator_reduce() passes ($carry, $value), and iterator_any(),
 *    iterator_all(), iterator_find() and iterator_find_key() pass
 *    ($value, $key);
 *  - lazy functions return a non-rewindable \Iterator and preserve keys,
 *    except iterator_keys() and iterator_values(), which produce lists;
 *  - terminal functions stop pulling from the input as soon as the result
 *    is known;
 *  - argument validation happens at call time, not on first iteration.
 *
 * This file intentionally does NOT declare strict_types: callbacks invoked by
 * internal functions (array_map() etc.) use coercive typing, and the polyfill
 * mirrors that, e.g. iterator_map([123], strlen(...)) yields 3.
 */

if (!\defined('ARRAY_FILTER_USE_VALUE')) {
    // Added natively in PHP 8.6.
    \define('ARRAY_FILTER_USE_VALUE', 0);
}

if (!\function_exists('iterator_map')) {
    /**
     * Lazy counterpart of array_map() with a single input: applies $callback
     * to every value, preserving keys.
     */
    function iterator_map(iterable $iterator, callable $callback): \Iterator
    {
        return (static function () use ($iterator, $callback): \Generator {
            foreach ($iterator as $key => $value) {
                yield $key => $callback($value);
            }
        })();
    }
}

if (!\function_exists('iterator_filter')) {
    /**
     * Lazy counterpart of array_filter(): yields entries accepted by
     * $callback (or whose value is truthy when no callback is given),
     * preserving keys. $mode selects the callback arguments exactly like
     * array_filter(): ARRAY_FILTER_USE_VALUE ($value), ARRAY_FILTER_USE_KEY
     * ($key) or ARRAY_FILTER_USE_BOTH ($value, $key).
     *
     * @throws \ValueError when $mode is not one of the constants above
     */
    function iterator_filter(iterable $iterator, ?callable $callback = null, int $mode = ARRAY_FILTER_USE_VALUE): \Iterator
    {
        if ($mode !== ARRAY_FILTER_USE_VALUE && $mode !== ARRAY_FILTER_USE_KEY && $mode !== ARRAY_FILTER_USE_BOTH) {
            throw new \ValueError('iterator_filter(): Argument #3 ($mode) must be one of ARRAY_FILTER_USE_VALUE, ARRAY_FILTER_USE_KEY, or ARRAY_FILTER_USE_BOTH');
        }

        return (static function () use ($iterator, $callback, $mode): \Generator {
            foreach ($iterator as $key => $value) {
                if ($callback === null) {
                    $keep = $value;
                } elseif ($mode === ARRAY_FILTER_USE_KEY) {
                    $keep = $callback($key);
                } elseif ($mode === ARRAY_FILTER_USE_BOTH) {
                    $keep = $callback($value, $key);
                } else {
                    $keep = $callback($value);
                }
                if ($keep) {
                    yield $key => $value;
                }
            }
        })();
    }
}

if (!\function_exists('iterator_slice')) {
    /**
     * Lazy counterpart of array_slice(): skips the first $offset entries and
     * yields at most $length of the following ones (all of them when $length
     * is null), preserving keys.
     *
     * Unlike array_slice(), negative values are rejected (they would require
     * buffering the whole input) and keys are always preserved. Never pulls
     * more than $offset + $length entries; with $length = 0 the input is not
     * touched at all.
     *
     * @throws \ValueError when $offset or $length is negative
     */
    function iterator_slice(iterable $iterator, int $offset, ?int $length = null): \Iterator
    {
        if ($offset < 0) {
            throw new \ValueError('iterator_slice(): Argument #2 ($offset) must be greater than or equal to 0');
        }
        if ($length !== null && $length < 0) {
            throw new \ValueError('iterator_slice(): Argument #3 ($length) must be greater than or equal to 0');
        }

        return (static function () use ($iterator, $offset, $length): \Generator {
            if ($length === 0) {
                return;
            }
            $skipped = 0;
            $taken = 0;
            foreach ($iterator as $key => $value) {
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                yield $key => $value;
                if ($length !== null && ++$taken >= $length) {
                    return;
                }
            }
        })();
    }
}

if (!\function_exists('iterator_keys')) {
    /**
     * Lazy counterpart of array_keys() (without its filtering parameters):
     * yields the keys of the iterable as a list.
     */
    function iterator_keys(iterable $iterator): \Iterator
    {
        return (static function () use ($iterator): \Generator {
            foreach ($iterator as $key => $_) {
                yield $key;
            }
        })();
    }
}

if (!\function_exists('iterator_values')) {
    /**
     * Lazy counterpart of array_values(): yields the values of the iterable
     * as a list.
     */
    function iterator_values(iterable $iterator): \Iterator
    {
        return (static function () use ($iterator): \Generator {
            foreach ($iterator as $value) {
                yield $value;
            }
        })();
    }
}

if (!\function_exists('iterator_reduce')) {
    /**
     * Counterpart of array_reduce(): $carry = $callback($carry, $value) for
     * every value, starting from $initial.
     */
    function iterator_reduce(iterable $iterator, callable $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($iterator as $value) {
            $carry = $callback($carry, $value);
        }

        return $carry;
    }
}

if (!\function_exists('iterator_any')) {
    /**
     * Counterpart of array_any(): returns true if $callback($value, $key)
     * returns a truthy value for at least one entry. Stops at the first match.
     */
    function iterator_any(iterable $iterator, callable $callback): bool
    {
        foreach ($iterator as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }
}

if (!\function_exists('iterator_all')) {
    /**
     * Counterpart of array_all(): returns true if $callback($value, $key)
     * returns a truthy value for every entry. Stops at the first failure.
     * Returns true for an empty iterable.
     */
    function iterator_all(iterable $iterator, callable $callback): bool
    {
        foreach ($iterator as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }
}

if (!\function_exists('iterator_find')) {
    /**
     * Counterpart of array_find(): returns the value of the first entry for
     * which $callback($value, $key) returns a truthy value, or null.
     * Stops at the first match.
     */
    function iterator_find(iterable $iterator, callable $callback): mixed
    {
        foreach ($iterator as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}

if (!\function_exists('iterator_find_key')) {
    /**
     * Counterpart of array_find_key(): returns the key of the first entry for
     * which $callback($value, $key) returns a truthy value, or null.
     * Stops at the first match.
     *
     * Note: unlike array keys, iterator keys may themselves be null, so a null
     * result is ambiguous for such iterators.
     */
    function iterator_find_key(iterable $iterator, callable $callback): mixed
    {
        foreach ($iterator as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }
}

if (!\function_exists('iterator_first')) {
    /**
     * Counterpart of array_first(): returns the first value of the iterable,
     * or null when it is empty. Pulls at most one entry from the input.
     */
    function iterator_first(iterable $iterator): mixed
    {
        foreach ($iterator as $value) {
            return $value;
        }

        return null;
    }
}
