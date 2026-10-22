<?php

declare(strict_types=1);

/**
 * Generic test harness: installs phasync\plug\Loader with a small, fixed map (see hooks.php)
 * and then requires the target file -- directly in 'wrapper' mode, through phasync\plug\path()
 * in 'cache' mode, exactly as e2e/app/swerve.php does. One process per run, since
 * Loader::install() runs once per process; tests/LoaderTest.php runs this as a subprocess.
 *
 * Usage: php harness.php <cacheDir> <excludePrefixesCSV, may be empty> <targetFile> [mode]
 */

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/hooks.php'; // native require: the wrapper is not installed yet

[, $cacheDir, $excludeCsv, $target] = $argv;
$mode = $argv[4] ?? 'wrapper';

$map = [
    'functions' => [
        'strtoupper' => 'FixtureHooks\\captured_strtoupper',
        'array_sum'  => 'FixtureHooks\\captured_array_sum',
    ],
    // Same mapping e2e/app/swerve.php uses for the real thing: PHP_SAPI is a constant, mapped to
    // the replacement *function* phasync-babel calls in its place (phasync-hooked has no map.php
    // entry for it -- that file only lists functions).
    'constants' => [
        'PHP_SAPI' => 'phasync\\hooked\\php_sapi',
    ],
];
// Real usage (see e2e/app/swerve.php) always excludes phasync-babel's own path: Babel::translate()
// lazily autoloads its own classes (phasync\Babel\Unit, Shim, ...) through Composer, and that
// require goes through this same wrapper; translating phasync-babel's own source recursively is
// not safe (Babel::translate() is not reentrant on the same instance) and breaks class loading.
// Confirmed by this harness: dropping this line reproduces "Class phasync\Babel\Unit not found".
$exclude = [\dirname(__DIR__, 2) . '/vendor/phasync/babel'];
if ('' !== $excludeCsv) {
    \array_push($exclude, ...\explode(',', $excludeCsv));
}

\phasync\plug\Loader::install($map, $cacheDir, $exclude, mode: $mode);

require 'cache' === $mode ? \phasync\plug\path($target, \dirname($target)) : $target;
