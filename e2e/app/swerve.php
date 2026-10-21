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

$pluginOn = '1' === \getenv('PLUG_LOADER_ON');
$plugMode = $pluginOn ? (\getenv('PLUG_MODE') ?: 'wrapper') : null;
$devRoot  = \dirname(__DIR__, 3); // ~/dev/phasync-plug/e2e/app -> ~/dev

if ('cache' === $plugMode) {
    // 'cache' mode rewrites include/require only in a file's own source, at translate time: a
    // file loaded before it was translated keeps calling the *native* require forever, even
    // after the loader is installed ('wrapper' mode has no such gap, since it intercepts every
    // require at the stream level, dynamically, regardless of when the requiring code itself was
    // compiled). Composer's ClassLoader must therefore never load natively before translation
    // starts, or every class it later autoloads -- Predis included -- stays untranslated for the
    // rest of the process (confirmed: 10 concurrent /redis took ~10s, not ~1s, before this fix).
    // So only phasync-plug's and phasync-babel's own files are required directly here, natively
    // (both packages are excluded from translation anyway, so it would change nothing if they
    // went through translation too). phasync-plug's path.php guards its own function
    // declaration (function_exists()) specifically for this: Composer's "files" autoload entries
    // are always required unconditionally (unlike a class, which an autoloader skips once already
    // declared), so without that guard Composer's later, normal load of the same file would
    // redeclare the function fatally; shims.php/shims-strict.php/hooked's _functions.php need no
    // such guard, since this bootstrap does not also pre-require them (nothing here needs them
    // yet) -- Composer's real autoloader loads those the normal way, once, when it itself first
    // loads -- the *first* require of vendor/autoload.php happens inside app.php, which *is*
    // translated (and so, in turn, is every file it requires; this is why Predis's own files,
    // and everything else "every application and vendor file" is meant to cover, are reached:
    // Composer's own require($file) calls are rewritten too, recursively).
    require "$devRoot/phasync-babel/src/Runtime.php";
    require "$devRoot/phasync-babel/src/Unit.php";
    require "$devRoot/phasync-babel/src/Babel.php";
    require "$devRoot/phasync-plug/src/Loader.php";
    require "$devRoot/phasync-plug/src/path.php";
} else {
    require __DIR__ . '/vendor/autoload.php';
}

if ($pluginOn) {
    \phasync\plug\Loader::install(
        ['functions' => require "$devRoot/phasync-hooked/src/map.php"],
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
return require 'cache' === $plugMode ? \phasync\plug\path('app.php', __DIR__) : __DIR__ . '/app.php';
