<?php

declare(strict_types=1);

/**
 * Runs tests/fixtures/harness.php as its own process (Loader::install() runs once per process,
 * so each scenario is a fresh PHP), and returns its stdout/stderr/exit code.
 *
 * @param list<string> $exclude path prefixes passed to Loader::install()
 * @param array<string, string> $env extra environment variables (e.g. PLUG_MARKER)
 *
 * @return array{out: string, err: string, code: int}
 */
function run_harness(string $cacheDir, array $exclude, string $target, array $env = []): array
{
    $cmd = [
        \PHP_BINARY,
        __DIR__ . '/fixtures/harness.php',
        $cacheDir,
        \implode(',', $exclude),
        $target,
    ];
    $process = \proc_open(
        $cmd,
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

    return ['out' => $out, 'err' => $err, 'code' => $code];
}

/** A temporary directory for a test's own use, removed after the test. */
function plug_temp_dir(): string
{
    $dir = \sys_get_temp_dir() . '/phasync-plug-test-' . \bin2hex(\random_bytes(6));
    \mkdir($dir, 0777, true);
    $GLOBALS['plug_temp_dirs'][] = $dir;

    return $dir;
}

uses()->afterEach(function (): void {
    foreach ($GLOBALS['plug_temp_dirs'] ?? [] as $dir) {
        \exec('rm -rf ' . \escapeshellarg($dir));
    }
    $GLOBALS['plug_temp_dirs'] = [];
})->in(__DIR__);
