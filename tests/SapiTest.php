<?php

declare(strict_types=1);

/**
 * End to end: a small application translated through the loader (echo, header(), ob_start()/
 * ob_get_clean(), php://input), run inside phasync\hooked\sapi(). Proves phasync-babel's
 * rewriting, phasync-plug's loader and phasync-hooked's sapi() replacements compose correctly for
 * a real request, not just each piece tested in isolation.
 */

/**
 * @return array{result: array{output: string, headersSent: array}, code: int, err: string}
 */
function run_sapi_harness(string $cacheDir, string $target, string $mode, string $body = ''): array
{
    $process = \proc_open(
        [\PHP_BINARY, __DIR__ . '/fixtures/sapi_harness.php', $cacheDir, '', $target, $mode, $body],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $out = \stream_get_contents($pipes[1]);
    $err = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $code = \proc_close($process);
    $marker = "\0SAPI-HARNESS-RESULT\0";
    $pos = \strrpos($out, $marker);

    return [
        'result' => false === $pos ? null : \json_decode(\substr($out, $pos + \strlen($marker)), true),
        'code' => $code,
        'err' => $err,
    ];
}

test('a translated application\'s echo, header(), ob_start()/ob_get_clean() and php://input reach sapi()', function (string $mode) {
    $cacheDir = plug_temp_dir();
    $target   = __DIR__ . '/fixtures/targets/sapi_app.php';

    $run = run_sapi_harness($cacheDir, $target, $mode, 'the-request-body');

    expect($run['code'])->toBe(0, $run['err']);
    expect($run['err'])->toBe('');
    expect($run['result']['output'])->toBe('buffered-echoed body=the-request-body');
    expect($run['result']['headersSent'])->toHaveCount(1);
    [$status, $statusLine, $headers] = $run['result']['headersSent'][0];
    expect($status)->toBe(200);
    expect($statusLine)->toBeNull();
    expect($headers)->toBe(['X-From-App: 1', 'Content-type: text/html; charset=UTF-8']);
})->with(['wrapper', 'cache']);

test('the same translated application run twice reuses the on-disk cache', function () {
    $cacheDir = plug_temp_dir();
    $target   = __DIR__ . '/fixtures/targets/sapi_app.php';

    $first  = run_sapi_harness($cacheDir, $target, 'cache', 'body-one');
    $second = run_sapi_harness($cacheDir, $target, 'cache', 'body-two');

    expect($first['code'])->toBe(0, $first['err']);
    expect($second['code'])->toBe(0, $second['err']);
    expect($first['result']['output'])->toBe('buffered-echoed body=body-one');
    expect($second['result']['output'])->toBe('buffered-echoed body=body-two');
});
