<?php

declare(strict_types=1);

$targets = __DIR__ . '/fixtures/targets';

test('a translated file keeps its original __FILE__/__DIR__, and a mapped call reaches the hooked replacement', function (string $mode) use ($targets) {
    $cacheDir = plug_temp_dir();
    $marker   = plug_temp_dir() . '/marker';

    $result = run_harness($cacheDir, [], "$targets/basic.php", ['PLUG_MARKER' => $marker], $mode);

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
})->with(['wrapper', 'cache']);

test('include_once/require_once dedup across translated files, and a plain include runs again', function (string $mode) use ($targets) {
    $cacheDir = plug_temp_dir();

    $result = run_harness($cacheDir, [], "$targets/include_once.php", mode: $mode);

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['file'])->toBe(realpath("$targets/include_once.php"));
    expect($data['runs'])->toBe(2); // the require_once/include_once pair deduped; the plain include ran once more
})->with(['wrapper', 'cache']);

test('a path under an excluded prefix is served unchanged: no translation happens there', function (string $mode) use ($targets) {
    $cacheDir = plug_temp_dir();
    $marker   = plug_temp_dir() . '/marker';
    $excludedDir = realpath(__DIR__ . '/fixtures/excluded');

    $result = run_harness($cacheDir, [$excludedDir], "$excludedDir/excluded_marker.php", ['PLUG_MARKER' => $marker], $mode);

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['upper'])->toBe('XYZ'); // native strtoupper(), same visible result
    expect(file_exists($marker))->toBeFalse(); // but the hooked replacement never ran
})->with(['wrapper', 'cache']);

test('a cache hit on a second, separate process skips re-translating: the cache file is written once', function (string $mode) use ($targets) {
    $cacheDir = plug_temp_dir();
    // 'wrapper': one flat file named by its content hash. 'cache': one file mirroring the
    // original absolute path (plus its sibling .key file).
    $glob = 'wrapper' === $mode ? "$cacheDir/*.php" : "$cacheDir$targets/basic.php";

    $first = run_harness($cacheDir, [], "$targets/basic.php", mode: $mode);
    expect($first['code'])->toBe(0, $first['err']);

    $files = glob($glob);
    expect($files)->toHaveCount(1);
    $mtimeAfterFirst = filemtime($files[0]);

    // a brand new process, same cache directory and the same target: the cache must be reused,
    // never calling Babel::translate() again
    usleep(1_100_000); // mtime has 1-second resolution: prove the file was NOT rewritten
    $second = run_harness($cacheDir, [], "$targets/basic.php", mode: $mode);
    expect($second['code'])->toBe(0, $second['err']);
    expect($second['out'])->toBe($first['out']);

    $filesAfter = glob($glob);
    expect($filesAfter)->toHaveCount(1); // no second cache file was created
    expect(filemtime($filesAfter[0]))->toBe($mtimeAfterFirst); // and the existing one was not rewritten
})->with(['wrapper', 'cache']);

test('cache mode: the cache mirror file is cached by opcache', function () use ($targets) {
    $cacheDir = plug_temp_dir();
    $script = plug_temp_dir() . '/opcache-check.php';
    file_put_contents($script, '<?php
        require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
        \phasync\plug\Loader::install([\'functions\' => []], ' . var_export($cacheDir, true) . ', [' . var_export(dirname(__DIR__) . '/vendor/phasync/babel', true) . '], mode: \'cache\');
        $resolved = \phasync\plug\path(' . var_export("$targets/basic.php", true) . ', ' . var_export($targets, true) . ');
        $before = opcache_get_status(true);
        ob_start(); include $resolved; ob_end_clean(); // basic.php echoes JSON on every include; discard it
        ob_start(); include $resolved; ob_end_clean();
        ob_start(); include $resolved; ob_end_clean();
        $after = opcache_get_status(true);
        echo isset($after["scripts"][$resolved]) ? "cached" : "NOT-cached", " ";
        echo ($after["opcache_statistics"]["hits"] - $before["opcache_statistics"]["hits"]);
    ');

    $result = run_harness_script($script, ['-d', 'opcache.enable_cli=1', '-d', 'opcache.enable=1', '-d', 'opcache.file_update_protection=0']);

    expect($result['out'])->toBe('cached 2'); // 1 compile, 2 served from opcache's shared memory
});

test("cache mode: a sibling path built from Reflection's mirror-path getFileName() (Symfony's compiled DI container does exactly this to find a sibling file) resolves to the same mirror as a direct require, not a nested mirror of it", function () use ($targets) {
    $cacheDir = plug_temp_dir();

    $result = run_harness($cacheDir, [], "$targets/reflect_sibling.php", mode: 'cache');

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    expect($data['interface'])->toBeTrue(); // both require_once calls landed on the one file: no redeclare
    // ReflectionClass is itself mapped (see install()): getFileName() now reports the original
    // path, same as __FILE__/__DIR__, not the cache mirror -- so this never had a mirror path to
    // build a sibling path from in the first place. Still worth keeping as a regression case for
    // the mirror-of-mirror bug itself, in case that mapping is ever removed.
    expect($data['reflectedFile'])->toBe(realpath("$targets/reflect_sibling.php"));

    // Exactly one mirror of the sibling exists on disk -- not a second, nested one.
    $mirrors = glob($cacheDir . realpath("$targets/reflect_sibling_target.php"));
    expect($mirrors)->toHaveCount(1);
    $nested = glob($cacheDir . $cacheDir . '*', GLOB_NOSORT);
    expect($nested)->toBe([]);
});

test('cache mode: ReflectionClass, ReflectionObject, ReflectionEnum, ReflectionFunction and ReflectionMethod all report the original path, a Symfony-Kernel::getProjectDir()-style dirname() of one resolves the real project root, and get_included_files() lists originals too', function () use ($targets) {
    $cacheDir = plug_temp_dir();

    $result = run_harness($cacheDir, [], "$targets/reflection_types.php", mode: 'cache');

    expect($result['code'])->toBe(0, $result['err']);
    $data = json_decode($result['out'], true);
    foreach (['class', 'object', 'enum', 'function', 'method'] as $kind) {
        expect($data[$kind])->toBe($data['original'], "Reflection$kind reported the mirror, not the original");
    }
    expect($data['projectDir'])->toBe(\dirname($data['original']));
    expect($data['includedFiles'])->toContain($data['original']);
    foreach ($data['includedFiles'] as $file) {
        expect($file)->not->toStartWith($cacheDir);
    }
});

test("non-include file operations behave natively (no wrapper is involved in 'cache' mode either)", function () use ($targets) {
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
 * @param list<string> $phpArgs extra arguments for the PHP binary itself (e.g. -d ini=value), before the script
 *
 * @return array{out: string, err: string, code: int}
 */
function run_harness_script(string $script, array $phpArgs = []): array
{
    $process = proc_open([PHP_BINARY, ...$phpArgs, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['out' => $out, 'err' => $err, 'code' => 0];
}
