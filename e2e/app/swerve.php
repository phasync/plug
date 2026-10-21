<?php

declare(strict_types=1);

/**
 * e2e/app's bootstrap. PHP compiles a whole file before running any of its statements, so the
 * loader cannot translate the file that installs it -- this file must stay a thin bootstrap:
 * load the loader, install it, then require the real application (app.php) as a *separate*
 * file, so that app.php's own source (and everything it requires: Predis) is compiled only
 * after the loader is installed, and so goes through it.
 *
 * Toggled by three things outside this file: whether swerve itself was started with --ext; the
 * PLUG_LOADER_ON environment variable (1: install the loader; unset/0: don't); and, when it is
 * on, PLUG_MODE ('wrapper', the default, or 'cache'). app.php's route code is plain, ordinary PHP
 * that calls blocking native functions directly regardless of any of this.
 */

require __DIR__ . '/vendor/autoload.php';

$mode = \getenv('PLUG_LOADER_ON') === '1';
if ($mode) {
    $plugMode = \getenv('PLUG_MODE') ?: 'wrapper';
    $devRoot  = \dirname(__DIR__, 3); // ~/dev/phasync-plug/e2e/app -> ~/dev
    \phasync\plug\Loader::install(
        ['functions' => require __DIR__ . '/vendor/phasync/hooked/src/map.php'],
        \getenv('PLUG_CACHE_DIR') ?: __DIR__ . '/var/cache',
        [
            "$devRoot/phasync",
            "$devRoot/swerve",
            "$devRoot/phasync-babel",
            "$devRoot/phasync-hooked",
            "$devRoot/phasync-plug/src", // phasync-plug's own library code; e2e/app lives in the same
            // repo (phasync-plug/e2e/app) purely for this proof's convenience and must stay translated
        ],
        mode: $plugMode,
    );
}

// 'cache' mode has no stream wrapper: the application's own entry file must be opened through
// phasync\plug\path() explicitly (babel's includeHandler then rewrites app.php's own
// requires -- vendor/autoload.php, Predis, the templates -- to call it too, recursively).
return require ($mode && 'cache' === $plugMode) ? \phasync\plug\path('app.php', __DIR__) : __DIR__ . '/app.php';
