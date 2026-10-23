<?php

declare(strict_types=1);

/**
 * Process B of the Serializor end-to-end test (tests/LoaderTest.php; process A is
 * serializor_pack.php). Mirrors e2e/app/swerve.php's own 'cache' mode bootstrap order exactly
 * (see that file's docblock): Composer's ClassLoader must never load natively before
 * Loader::install() runs, or every class it later autoloads -- Serializor's own classes
 * included -- stays untranslated for the rest of the process (the README's "What didn't work"
 * finding). So phasync-babel's and phasync-plug's own class files are required directly here,
 * natively (both are excluded from translation below anyway), and only the real test body
 * (serializor_e2e_app.php) is required through phasync\plug\path() -- which is what makes
 * *that* file's own `require 'vendor/autoload.php'` get rewritten to call path() too,
 * recursively, reaching Composer's ClassLoader and everything it autoloads afterward.
 *
 * Usage: php serializor_e2e_bootstrap.php <cacheDir> <serialFile> <markerFile>
 */

$root = \dirname(__DIR__, 2); // tests/fixtures -> phasync-plug

require "$root/vendor/phasync/babel/src/Runtime.php";
require "$root/vendor/phasync/babel/src/Unit.php";
require "$root/vendor/phasync/babel/src/Babel.php";
require "$root/src/Loader.php";
require "$root/src/path.php";
require __DIR__ . '/hooks.php'; // native require: the wrapper is not installed yet

[, $cacheDir, $serialFile, $markerFile] = $argv;

\putenv("PLUG_MARKER=$markerFile");
\putenv("PLUG_SERIAL_FILE=$serialFile");

\phasync\plug\Loader::install(
    [
        'functions' => [
            'strtoupper' => 'FixtureHooks\\captured_strtoupper',
        ],
    ],
    $cacheDir,
    ["$root/vendor/phasync/babel"],
    mode: 'cache',
);

require \phasync\plug\path(__DIR__ . '/serializor_e2e_app.php', __DIR__);
