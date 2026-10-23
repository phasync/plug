<?php

declare(strict_types=1);

/**
 * Like `harness.php`, but runs the target file inside `\phasync\hooked\sapi()` against a
 * {@see SapiTestDouble}, with phasync-hooked's *real* `map.php` (every function it implements,
 * not the tiny, test-only one `harness.php`/`hooks.php` use) -- the end-to-end proof that a
 * translated application's `echo`, `header()`, `ob_start()`/`ob_get_clean()` and `php://input`
 * reach `sapi()`'s request-scoped replacements exactly as they would untranslated, under
 * ext-phasync.
 *
 * Usage: `php sapi_harness.php <cacheDir> <excludePrefixesCSV, may be empty> <targetFile> <mode>
 * <request body, may be empty>`. Prints this request's drained output and the arguments every
 * `send_headers()` call received, as JSON, after a `"\0SAPI-HARNESS-RESULT\0"` marker (the target
 * runs inside the request: any output it fails to route there -- there should be none -- would
 * otherwise corrupt the JSON on this process's own real stdout).
 */

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/SapiTestDouble.php';

[, $cacheDir, $excludeCsv, $target, $mode, $body] = $argv + [5 => ''];

$map = [
    'functions' => require \dirname(__DIR__, 2) . '/vendor/phasync/hooked/src/map.php',
    'constants' => ['PHP_SAPI' => 'phasync\\hooked\\php_sapi'],
];
$exclude = [\dirname(__DIR__, 2) . '/vendor/phasync/babel'];
if ('' !== $excludeCsv) {
    \array_push($exclude, ...\explode(',', $excludeCsv));
}

\phasync\plug\Loader::install($map, $cacheDir, $exclude, mode: $mode);

$sapi = new SapiTestDouble('' !== $body ? $body : null);
phasync::run(function () use ($sapi, $target, $mode) {
    \phasync\hooked\sapi(function () use ($target, $mode) {
        require 'cache' === $mode ? \phasync\plug\path($target, \dirname($target)) : $target;
    }, $sapi);
});

echo "\0SAPI-HARNESS-RESULT\0" . \json_encode(['output' => $sapi->drain(), 'headersSent' => $sapi->headersSent]);
