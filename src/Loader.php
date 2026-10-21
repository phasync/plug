<?php

declare(strict_types=1);

namespace phasync\plug;

use phasync\Babel\Babel;
use phasync\Babel\Runtime;

/**
 * A `file://` stream wrapper that translates PHP source through phasync/babel as PHP opens it
 * for include, so that calls to blocking global functions become calls to their phasync/hooked
 * replacements -- without the phasync C extension. Every other file operation
 * (file_get_contents, fopen, is_file, mkdir, unlink, touch, rename, opendir, stat, ...) passes
 * through to the native file wrapper, unchanged.
 *
 * Translated code keeps running under its original path: PHP is told the *original* path was
 * opened (the translated bytes are served through an in-memory stream), so __FILE__, __DIR__,
 * include_once deduplication, error messages and stack traces all read as they did without this
 * wrapper. This is the same mechanism phasync/babel's own test harness
 * (phasync-babel/tests/IncludeWrapper.php) uses, extended with a disk cache and an exclusion
 * list.
 *
 * A path under one of the excluded prefixes (phasync, swerve, phasync-babel, phasync-hooked and
 * phasync/plug itself) is served unchanged: this loader must never translate the libraries that
 * do the translating or already run natively against phasync.
 *
 * Translated source is cached on disk, keyed by the file's real path, mtime and size, and a hash
 * of the map and phasync/babel's own version/reference, so a file is translated once -- not per
 * request, and not per worker, since the cache is shared on disk by every worker and every
 * process that uses the same cache directory.
 *
 * See the project README for the opcache finding this class's docblock cannot change: PHP's
 * opcache does not cache scripts opened through a non-default 'file' stream wrapper (confirmed
 * empirically -- no combination of url_stat/stream_stat/stream_set_option/
 * opcache.enable_file_override restores it), so every include/require of a translated file is
 * recompiled from source by PHP every time it runs. Under Swerve this cost is paid once per
 * worker boot (application files are required once, not per request); it would be a severe
 * regression under a per-request bootstrap such as PHP-FPM.
 */
final class Loader
{
    /** STREAM_OPEN_FOR_INCLUDE, from php_streams.h: PHP passes it when include/require opens a file. */
    private const OPEN_FOR_INCLUDE = 0x80;

    /** STREAM_WILL_CAST, from php_streams.h: the opener will cast the stream to a descriptor (proc_open()'s 'file' descriptor spec entries). */
    private const WILL_CAST = 0x20;

    private static ?Babel $babel = null;

    private static string $cacheDir;

    /** @var list<string> normalized (trailing slash), symlink-resolved real paths */
    private static array $exclude = [];

    private static bool $installed = false;

    private static string $babelVersion = 'unknown';

    /** @var resource|null set by PHP itself when a stream context is given to fopen() et al. */
    public $context;

    /** @var resource */
    private $h;

    /** @var resource */
    private $d;

    /**
     * proc_open() casts the stream to a file descriptor with PHP_STREAM_CAST_RELEASE and closes
     * it at once: a native stream then hands the descriptor over, but a user stream's close
     * closes it before the child is spawned ("posix_spawn() failed: Bad file descriptor").
     */
    private bool $release = false;

    /** @var list<resource> kept alive after proc_open() released them, see stream_close() */
    private static array $released = [];

    /** Instances come only from PHP's stream wrapper machinery, through install(). */
    private function __construct()
    {
    }

    /**
     * The one entry point: builds the phasync/babel translator from $map, configures
     * phasync\Babel\Runtime with it (for class names and callables only known at run time), and
     * registers this class as the 'file' stream wrapper.
     *
     * @param array{functions?: array<string,string>, classes?: array<string,string>} $map      phasync-babel's map (phasync/hooked's src/map.php, or a subset of it)
     * @param string                                                                  $cacheDir  directory for cached translations, created if missing; shared safely by several workers/processes (translate-then-atomic-rename)
     * @param list<string>                                                            $excludePrefixes path prefixes never translated (phasync, swerve, phasync-babel, phasync-hooked, phasync-plug); symlinks are resolved
     */
    public static function install(array $map, string $cacheDir, array $excludePrefixes): void
    {
        if (self::$installed) {
            throw new \LogicException(self::class . '::install() runs once per process');
        }
        self::$babel = new Babel(functions: $map['functions'] ?? [], classes: $map['classes'] ?? []);
        Runtime::configure(self::$babel);

        self::$cacheDir = \rtrim($cacheDir, '/');
        if (!\is_dir(self::$cacheDir) && !@\mkdir(self::$cacheDir, 0777, true) && !\is_dir(self::$cacheDir)) {
            throw new \RuntimeException(self::class . "::install(): cannot create cache directory $cacheDir");
        }

        self::$exclude = [];
        foreach ($excludePrefixes as $prefix) {
            $real              = \realpath($prefix);
            self::$exclude[] = \rtrim(false !== $real ? $real : $prefix, '/') . '/';
        }

        self::$babelVersion = self::resolveBabelVersion();

        \stream_wrapper_unregister('file');
        \stream_wrapper_register('file', self::class);
        self::$installed = true;
    }

    /** Whether install() has already run in this process. */
    public static function isInstalled(): bool
    {
        return self::$installed;
    }

    /** phasync/babel's installed reference (a git commit) or version, for the cache key; 'unknown' when Composer's InstalledVersions is unavailable. */
    private static function resolveBabelVersion(): string
    {
        if (!\class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled('phasync/babel')) {
            return 'unknown';
        }

        return \Composer\InstalledVersions::getReference('phasync/babel')
            ?? \Composer\InstalledVersions::getPrettyVersion('phasync/babel')
            ?? 'unknown';
    }

    /**
     * Runs $fn with the native 'file' wrapper restored, as babel's own test wrapper does:
     * unregister first, since restore() over a user wrapper keeps the user wrapper's class
     * registered underneath, growing a long-running worker by a per-call bit of memory.
     */
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

    /** Runs $fn with its warnings discarded: neither error handlers nor error_get_last() see them. */
    private static function silent(callable $fn): mixed
    {
        \set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            \restore_error_handler();
        }
    }

    private static function excluded(string $real): bool
    {
        foreach (self::$exclude as $prefix) {
            if (\str_starts_with($real, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $src translated through phasync/babel, from the on-disk cache when a cached translation of
     * the same real path, mtime, size, map and babel version already exists.
     */
    private static function translateCached(string $real, string $src): string
    {
        $stat = self::native(static fn () => @\stat($real));
        $key  = \hash('sha256', $real . "\0" . ($stat['mtime'] ?? 0) . "\0" . ($stat['size'] ?? 0) . "\0" . self::$babel->fingerprint . "\0" . self::$babelVersion);
        $cacheFile = self::$cacheDir . '/' . $key . '.php';

        $cached = self::native(static fn () => \is_file($cacheFile) ? \file_get_contents($cacheFile) : false);
        if (false !== $cached) {
            return $cached;
        }

        $out = self::$babel->translate($src, $real);
        self::native(static function () use ($cacheFile, $out): void {
            $tmp = $cacheFile . '.' . \getmypid() . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
            \file_put_contents($tmp, $out);
            \rename($tmp, $cacheFile); // atomic on the same filesystem; a concurrent writer overwrites with identical bytes
        });

        return $out;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if ($options & self::OPEN_FOR_INCLUDE) {
            $real = self::native(static fn () => \realpath($path));
            if (false === $real || !self::native(static fn () => \is_file($real))) {
                return false;
            }
            $src = self::native(static fn () => self::silent(static fn () => \file_get_contents($real)));
            if (false === $src) {
                return false;
            }
            $out = self::excluded($real) ? $src : self::translateCached($real, $src);

            $this->h = \fopen('php://memory', 'w+b');
            \fwrite($this->h, $out);
            \rewind($this->h);
            $opened_path = $real;

            return true;
        }

        // proc_open() casts the stream to a file descriptor with PHP_STREAM_CAST_RELEASE and closes
        // it at once: a native stream then hands the descriptor over, but a user stream's close
        // closes it before the child is spawned ("posix_spawn() failed: Bad file descriptor").
        $this->release = ($options & self::WILL_CAST) !== 0
            && 'proc_open' === (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '');

        $usePath = (bool) ($options & \STREAM_USE_PATH);
        // Silent: when the open fails, PHP reports it once for the caller
        // ('"...::stream_open" call failed'), as it reports a native open once; the inner
        // fopen()'s own warning would be a second.
        $this->h = self::native(fn () => self::silent(fn () => $this->context
            ? \fopen($path, $mode, $usePath, $this->context)
            : \fopen($path, $mode, $usePath)));

        return false !== $this->h;
    }

    public function stream_read(int $n): string|false
    {
        return \fread($this->h, $n);
    }

    public function stream_write(string $data): int|false
    {
        return \fwrite($this->h, $data);
    }

    public function stream_eof(): bool
    {
        return \feof($this->h);
    }

    public function stream_tell(): int|false
    {
        return \ftell($this->h);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return 0 === \fseek($this->h, $offset, $whence);
    }

    public function stream_flush(): bool
    {
        return \fflush($this->h);
    }

    public function stream_close(): void
    {
        if ($this->release) {
            // proc_open() owns the descriptor now and closes it after the spawn; keeping the
            // resource alive is the nearest a user stream gets to a release (it closes the
            // number once more at shutdown).
            self::$released[] = $this->h;

            return;
        }
        \fclose($this->h);
    }

    public function stream_stat(): array|false
    {
        return \fstat($this->h);
    }

    /** $op === 0: PHP asks whether locking works at all. */
    public function stream_lock(int $op): bool
    {
        return 0 === $op || \flock($this->h, $op);
    }

    public function stream_truncate(int $size): bool
    {
        return \ftruncate($this->h, $size);
    }

    /** @return resource */
    public function stream_cast(int $as)
    {
        return $this->h;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return match ($option) {
            \STREAM_OPTION_BLOCKING => \stream_set_blocking($this->h, (bool) $arg1),
            \STREAM_OPTION_READ_TIMEOUT => \stream_set_timeout($this->h, $arg1, (int) $arg2),
            \STREAM_OPTION_READ_BUFFER => 0 === \stream_set_read_buffer($this->h, \STREAM_BUFFER_NONE === $arg1 ? 0 : (int) $arg2),
            \STREAM_OPTION_WRITE_BUFFER => 0 === \stream_set_write_buffer($this->h, (int) $arg2),
            default => false,
        };
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return self::native(static fn () => match ($option) {
            \STREAM_META_TOUCH => \touch($path, ...$value),
            \STREAM_META_OWNER_NAME, \STREAM_META_OWNER => \chown($path, $value),
            \STREAM_META_GROUP_NAME, \STREAM_META_GROUP => \chgrp($path, $value),
            \STREAM_META_ACCESS => \chmod($path, $value),
            default => false,
        });
    }

    public function url_stat(string $path, int $flags): array|false
    {
        // The native stater reports nothing (the caller -- filemtime() and co. -- reports a
        // failure itself; file_exists() and co. report nothing); @ is not enough: error handlers
        // still see the warning.
        return self::native(static fn () => self::silent(static fn () => $flags & \STREAM_URL_STAT_LINK ? \lstat($path) : \stat($path)));
    }

    public function unlink(string $path): bool
    {
        return self::native(fn () => $this->context ? \unlink($path, $this->context) : \unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(fn () => $this->context ? \rename($from, $to, $this->context) : \rename($from, $to));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(fn () => $this->context ? \rmdir($path, $this->context) : \rmdir($path));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $recursive = (bool) ($options & \STREAM_MKDIR_RECURSIVE);

        return self::native(fn () => $this->context ? \mkdir($path, $mode, $recursive, $this->context) : \mkdir($path, $mode, $recursive));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        // silent for the reason given in stream_open()
        $this->d = self::native(fn () => self::silent(fn () => $this->context ? \opendir($path, $this->context) : \opendir($path)));

        return false !== $this->d;
    }

    public function dir_readdir(): string|false
    {
        return \readdir($this->d);
    }

    public function dir_rewinddir(): bool
    {
        \rewinddir($this->d);

        return true;
    }

    public function dir_closedir(): bool
    {
        \closedir($this->d);

        return true;
    }
}
