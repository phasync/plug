<?php

declare(strict_types=1);

namespace phasync\plug;

/**
 * 'cache' mode's replacements for the few global functions, besides Reflection, that report a
 * file path PHP itself resolved -- the cache mirror's, not the original's (see
 * {@see Loader::install()}'s own docblock on why; `getFileName()` symmetrically fixed by the
 * classes in Reflection/). Mapped into babel's map only in 'cache' mode: 'wrapper' mode never
 * hides the original path from these either, so needs no replacement.
 *
 * `Exception::getFile()`/`getTrace()` are `final` (confirmed: PHP itself refuses to let any
 * subclass override them) -- there is no way to fix those from here. They keep reporting the
 * cache mirror path; `Exception::getMessage()` and everything else about an exception is
 * unaffected.
 *
 * @internal
 */

/** @return list<string> */
function get_included_files(): array
{
    return \array_map(Loader::unmirror(...), \get_included_files());
}

/** @return list<string> */
function get_required_files(): array
{
    return \array_map(Loader::unmirror(...), \get_required_files());
}

/**
 * @return list<array{function: string, line?: int, file?: string, class?: string, object?: object, type?: string, args?: array}>
 */
function debug_backtrace(int $options = \DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): array
{
    // One extra frame requested (unless $limit is already "no limit"), and dropped below: native
    // debug_backtrace() never includes a frame for itself, but called from inside this wrapper
    // it would include one for *this* function, which the caller never asked for.
    $frames = \debug_backtrace($options, 0 === $limit ? 0 : $limit + 1);
    \array_shift($frames);
    foreach ($frames as &$frame) {
        if (isset($frame['file'])) {
            $frame['file'] = Loader::unmirror($frame['file']);
        }
    }

    return $frames;
}

/**
 * Built on this file's own debug_backtrace() replacement (already drops the extra frame and
 * unmirrors 'file'), formatted the way the native function does for the common case. Argument
 * representations are not reproduced byte-for-byte for every possible value the way the native
 * function's are -- this is meant for a human reading debug output, not for parsing.
 */
function debug_print_backtrace(int $options = 0, int $limit = 0): void
{
    foreach (debug_backtrace($options, $limit) as $i => $frame) {
        $where = isset($frame['file'], $frame['line']) ? "{$frame['file']}({$frame['line']})" : '[internal function]';
        $call = match ($frame['type'] ?? null) {
            '::' => "{$frame['class']}::{$frame['function']}()",
            '->' => "{$frame['class']}->{$frame['function']}()",
            default => "{$frame['function']}()",
        };
        echo "#{$i} {$where}: {$call}\n";
    }
}
