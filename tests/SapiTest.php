<?php

declare(strict_types=1);

/**
 * End to end: a small application translated through the loader (echo, header(), ob_start()/
 * ob_get_clean(), php://input, and a session round trip), run inside phasync\hooked\sapi().
 * Proves phasync-babel's rewriting, phasync-plug's loader and phasync-hooked's sapi() replacements
 * compose correctly for a real request, not just each piece tested in isolation.
 */

/**
 * @return array{result: array{output: string, headersSent: array}, code: int, err: string}
 */
function run_sapi_harness(string $cacheDir, string $target, string $mode, string $body = '', array $env = []): array
{
    $process = \proc_open(
        [\PHP_BINARY, __DIR__ . '/fixtures/sapi_harness.php', $cacheDir, '', $target, $mode, $body],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env + \getenv(),
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

function sapi_app_env(string $sessionDir, string $sessionId): array
{
    return ['PLUG_SESSION_SAVE_PATH' => $sessionDir, 'PLUG_SESSION_ID' => $sessionId];
}

test('a translated application\'s echo, header(), ob_start()/ob_get_clean(), php://input and session reach sapi()', function (string $mode) {
    $cacheDir    = plug_temp_dir();
    $sessionDir  = plug_temp_dir();
    $sessionId   = \bin2hex(\random_bytes(16));
    $target      = __DIR__ . '/fixtures/targets/sapi_app.php';

    $run = run_sapi_harness($cacheDir, $target, $mode, 'the-request-body', sapi_app_env($sessionDir, $sessionId));

    expect($run['code'])->toBe(0, $run['err']);
    expect($run['err'])->toBe('');
    expect($run['result']['output'])->toBe("buffered-echoed body=the-request-body session_id={$sessionId} hits=1");
    expect($run['result']['headersSent'])->toHaveCount(1);
    [$status, $statusLine, $headers] = $run['result']['headersSent'][0];
    expect($status)->toBe(200);
    expect($statusLine)->toBeNull();
    expect($headers)->toBe([
        "Set-Cookie: PHPSESSID={$sessionId}; path=/",
        'Expires: Thu, 19 Nov 1981 08:52:00 GMT',
        'Cache-Control: no-store, no-cache, must-revalidate',
        'Pragma: no-cache',
        'X-From-App: 1',
        'Content-type: text/html; charset=UTF-8',
    ]);
    expect(\file_get_contents("{$sessionDir}/sess_{$sessionId}"))->toBe('hits|i:1;');
})->with(['wrapper', 'cache']);

test('the same translated application run twice reuses the on-disk cache', function () {
    $cacheDir   = plug_temp_dir();
    $sessionDir = plug_temp_dir();
    $sessionId  = \bin2hex(\random_bytes(16));
    $target     = __DIR__ . '/fixtures/targets/sapi_app.php';
    $env        = sapi_app_env($sessionDir, $sessionId);

    $first  = run_sapi_harness($cacheDir, $target, 'cache', 'body-one', $env);
    $second = run_sapi_harness($cacheDir, $target, 'cache', 'body-two', $env);

    expect($first['code'])->toBe(0, $first['err']);
    expect($second['code'])->toBe(0, $second['err']);
    expect($first['result']['output'])->toBe("buffered-echoed body=body-one session_id={$sessionId} hits=1");
    expect($second['result']['output'])->toBe("buffered-echoed body=body-two session_id={$sessionId} hits=2");
});

test('a session round trip survives translation: the second request reads what the first wrote', function () {
    $cacheDir   = plug_temp_dir();
    $sessionDir = plug_temp_dir();
    $sessionId  = \bin2hex(\random_bytes(16));
    $target     = __DIR__ . '/fixtures/targets/sapi_app.php';
    $env        = sapi_app_env($sessionDir, $sessionId);

    $first  = run_sapi_harness($cacheDir, $target, 'wrapper', '', $env);
    $second = run_sapi_harness($cacheDir, $target, 'wrapper', '', $env);
    $third  = run_sapi_harness($cacheDir, $target, 'wrapper', '', $env);

    expect($first['result']['output'])->toContain('hits=1');
    expect($second['result']['output'])->toContain('hits=2');
    expect($third['result']['output'])->toContain('hits=3');
});
