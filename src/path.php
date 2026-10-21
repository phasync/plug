<?php

declare(strict_types=1);

namespace phasync\plug;

/**
 * 'cache' mode's include handler: resolves $path exactly as PHP's own include/require would,
 * from $fromDir (the original directory of the file resolving it), translates it if needed (the
 * same on-disk cache as everywhere else in this package) and returns the path to actually open --
 * a file in the cache mirror tree, or the original path when excluded. See
 * {@see Loader::install()}'s 'cache' mode and {@see Loader::resolve()}, which this calls.
 *
 * This is the one function phasync-babel's `includeHandler` option is configured with in 'cache'
 * mode, so every include/require in a translated file is rewritten to call it; it is also what
 * the entry point (swerve.php) calls explicitly for the application's own entry file, since that
 * file cannot translate its own source (PHP compiles it whole before running any of it).
 *
 * @throws \RuntimeException when nothing exists at $path (from $fromDir)
 */
if (!\function_exists(__NAMESPACE__ . '\\path')) {
    // Guarded: 'cache' mode's own bootstrap (see e2e/app/swerve.php) must make this function
    // available *before* Composer's own autoloader has run (it is what the application's first,
    // translated require of vendor/autoload.php itself calls) -- by requiring this file
    // natively, directly, ahead of time. Composer's "files" autoload entries are always required
    // unconditionally (unlike a class, which an autoloader skips once already declared), so
    // without this guard that normal, once-only load would redeclare the function fatally.
    function path(string $path, string $fromDir): string
    {
        return Loader::resolve($path, $fromDir);
    }
}
