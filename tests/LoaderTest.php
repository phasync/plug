<?php

declare(strict_types=1);

$targets = __DIR__ . '/fixtures/targets';

test('a translated file keeps its original __FILE__/__DIR__, and a mapped call reaches the hooked replacement', function () use ($targets) {
    $cacheDir = plug_temp_dir();
    $marker   = plug_temp_dir() . '/marker';

    $result = run_harness($cacheDir, [], "$targets/basic.php", ['PLUG_MARKER' => $marker]);

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['file'])->toBe(realpath("$targets/basic.php"));
    expect($data['dir'])->toBe(realpath($targets));
    expect($data['upper'])->toBe('ABC'); // behaviour unchanged
    expect($data['sum'])->toBe(6);

    // Both strtoupper() and array_sum() in basic.php were translated to the hooked replacements,
    // which append a sentinel to the marker file before delegating to the native function.
    expect(file_exists($marker))->toBeTrue();
    $marks = file_get_contents($marker);
    expect($marks)->toContain('HOOKED-strtoupper');
    expect($marks)->toContain('HOOKED-array_sum');
});

test('include_once/require_once dedup across translated files, and a plain include runs again', function () use ($targets) {
    $cacheDir = plug_temp_dir();

    $result = run_harness($cacheDir, [], "$targets/include_once.php");

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['file'])->toBe(realpath("$targets/include_once.php"));
    expect($data['runs'])->toBe(2); // the require_once/include_once pair deduped; the plain include ran once more
});

test('a path under an excluded prefix is served unchanged: no translation happens there', function () use ($targets) {
    $cacheDir = plug_temp_dir();
    $marker   = plug_temp_dir() . '/marker';
    $excludedDir = realpath(__DIR__ . '/fixtures/excluded');

    $result = run_harness($cacheDir, [$excludedDir], "$excludedDir/excluded_marker.php", ['PLUG_MARKER' => $marker]);

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['upper'])->toBe('XYZ'); // native strtoupper(), same visible result
    expect(file_exists($marker))->toBeFalse(); // but the hooked replacement never ran
});

test('a cache hit on a second, separate process skips re-translating: the cache file is written once', function () use ($targets) {
    $cacheDir = plug_temp_dir();

    $first = run_harness($cacheDir, [], "$targets/basic.php");
    expect($first['code'])->toBe(0, $first['err']);

    $files = glob("$cacheDir/*.php");
    expect($files)->toHaveCount(1);
    $mtimeAfterFirst = filemtime($files[0]);

    // a brand new process, same cache directory and the same target: translateCached() must
    // find the cache file and return its contents, never calling Babel::translate() again
    usleep(1_100_000); // mtime has 1-second resolution: prove the file was NOT rewritten
    $second = run_harness($cacheDir, [], "$targets/basic.php");
    expect($second['code'])->toBe(0, $second['err']);
    expect($second['out'])->toBe($first['out']);

    $filesAfter = glob("$cacheDir/*.php");
    expect($filesAfter)->toHaveCount(1); // no second cache file was created
    expect(filemtime($filesAfter[0]))->toBe($mtimeAfterFirst); // and the existing one was not rewritten
});

test('non-include file operations behave natively through the wrapper', function () use ($targets) {
    $cacheDir = plug_temp_dir();

    $result = run_harness($cacheDir, [], "$targets/fileops.php");

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['file_get_contents'])->toBe('hello');
    expect($data['fread'])->toBe('hello');
    expect($data['is_file'])->toBeTrue();
    expect($data['touch_mtime_matches'])->toBeTrue();
    expect($data['rename_old_gone'])->toBeTrue();
    expect($data['rename_new_there'])->toBeTrue();
    expect($data['stat_size'])->toBe(5);
    expect($data['opendir_entries'])->toBe(['b.txt']);
    expect($data['unlink_gone'])->toBeTrue();
    expect($data['rmdir_gone'])->toBeTrue();
});

test('Loader::install() refuses to run twice in the same process', function () {
    // A tiny standalone script (not through harness.php, which already installs once) that
    // installs twice itself.
    $script = plug_temp_dir() . '/twice.php';
    file_put_contents($script, '<?php
        require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
        \phasync\plug\Loader::install([\'functions\' => []], ' . var_export(plug_temp_dir(), true) . ', []);
        try {
            \phasync\plug\Loader::install([\'functions\' => []], ' . var_export(plug_temp_dir(), true) . ', []);
            echo "no-exception";
        } catch (\LogicException $e) {
            echo "LogicException";
        }
    ');

    $result = run_harness_script($script);
    expect($result['out'])->toBe('LogicException');
});

/**
 * Runs an arbitrary PHP script as its own process (used where harness.php's fixed map doesn't
 * fit the scenario).
 *
 * @return array{out: string, err: string, code: int}
 */
function run_harness_script(string $script): array
{
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['out' => $out, 'err' => $err, 'code' => 0];
}
