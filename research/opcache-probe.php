<?php

declare(strict_types=1);

/**
 * Empirical evidence for the opcache finding in the README: does PHP's opcache cache a script
 * opened through a custom 'file' stream wrapper (the mechanism phasync\plug\Loader, and
 * phasync-babel's own test harness, use to translate code under its original path)?
 *
 * Usage, from this directory (opcache.file_update_protection, 2s by default, skips caching a
 * file younger than that -- set it to 0 so a freshly (re)created target.php is still eligible):
 *   php -d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.file_update_protection=0 opcache-probe.php native
 *   php -d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.file_update_protection=0 opcache-probe.php wrapper
 *   php -d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.file_update_protection=0 -d opcache.enable_file_override=1 opcache-probe.php wrapper
 *
 * 'native' includes target.php three times without any stream wrapper registered: opcache
 * serves it from shared memory after the first compile, so hits increase and
 * opcache_get_status(true)['scripts'] lists it.
 *
 * 'wrapper' registers a pass-through 'file' wrapper (serves the same bytes, unchanged, under the
 * original path -- the same mechanism Loader uses, minus the translation) and includes the same
 * file three times: opcache never lists it, hits stay 0, and every include is a miss (recompiled
 * from source). No combination of opcache.enable_file_override, opcache.validate_timestamps,
 * stream_set_option or url_stat changes this -- confirmed by running this script with each.
 */

$target = __DIR__ . '/opcache-probe-target.php';
if (!\is_file($target)) {
    \file_put_contents($target, "<?php\nreturn 42;\n");
}

final class PassThroughWrapper
{
    private const OPEN_FOR_INCLUDE = 0x80;
    public $context;
    private $h;

    public static function register(): void
    {
        \stream_wrapper_unregister('file');
        \stream_wrapper_register('file', self::class);
    }

    private static function native(callable $fn): mixed
    {
        \stream_wrapper_unregister('file');
        \stream_wrapper_restore('file');
        try {
            return $fn();
        } finally {
            \stream_wrapper_unregister('file');
            \stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, &$opened_path): bool
    {
        if ($options & self::OPEN_FOR_INCLUDE) {
            $real = self::native(static fn () => \realpath($path));
            $src  = self::native(static fn () => \file_get_contents($real));
            $this->h = \fopen('php://memory', 'w+b');
            \fwrite($this->h, $src); // pass-through: no translation, just like a plain include
            \rewind($this->h);
            $opened_path = $real;

            return true;
        }
        $this->h = self::native(static fn () => \fopen($path, $mode));

        return false !== $this->h;
    }

    public function stream_read(int $n): string|false
    {
        return \fread($this->h, $n);
    }

    public function stream_eof(): bool
    {
        return \feof($this->h);
    }

    public function stream_stat(): array|false
    {
        return \fstat($this->h);
    }

    public function stream_close(): void
    {
        \fclose($this->h);
    }

    public function stream_set_option(int $o, int $a1, ?int $a2): bool
    {
        return false; // declared only to suppress "not implemented" warnings; irrelevant to the result
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return self::native(static fn () => $flags & \STREAM_URL_STAT_LINK ? @\lstat($path) : @\stat($path));
    }
}

$mode = $argv[1] ?? 'native';
if ('wrapper' === $mode) {
    PassThroughWrapper::register();
}

$before = \opcache_get_status(true);
include $target;
include $target;
include $target;
$after = \opcache_get_status(true);
$real  = \realpath($target);

echo "mode=$mode\n";
echo 'scripts_after=' . (isset($after['scripts'][$real]) ? 'present (cached)' : 'absent (never cached)') . "\n";
echo "hits: before={$before['opcache_statistics']['hits']} after={$after['opcache_statistics']['hits']}\n";
echo "misses: before={$before['opcache_statistics']['misses']} after={$after['opcache_statistics']['misses']}\n";
