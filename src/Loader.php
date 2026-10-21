<?php

declare(strict_types=1);

namespace phasync\plug;

use phasync\Babel\Babel;
use phasync\Babel\Runtime;

/**
 * Two ways to run application code translated through phasync/babel, so that calls to blocking
 * global functions become calls to their phasync/hooked replacements -- without the phasync C
 * extension. Both are reached only through {@see install()}.
 *
 * **'wrapper' mode** (the default): this class is itself a `file://` stream wrapper, translating
 * PHP source as PHP opens it for include. Every other file operation (file_get_contents, fopen,
 * is_file, mkdir, unlink, touch, rename, opendir, stat, ...) passes through to the native file
 * wrapper, unchanged. Translated code keeps running under its original path: PHP is told the
 * *original* path was opened (the translated bytes are served through an in-memory stream), so
 * __FILE__, __DIR__, include_once deduplication, error messages and stack traces all read as they
 * did without this wrapper. This is the same mechanism phasync/babel's own test harness
 * (phasync-babel/tests/IncludeWrapper.php) uses, extended with a disk cache and an exclusion
 * list. The cost, per the project README's opcache finding: PHP's opcache does not cache scripts
 * opened through a non-default 'file' stream wrapper, so every include/require of a translated
 * file is recompiled from source every time it runs -- paid once per worker boot under Swerve
 * (application files are required once, not per request), but a severe regression under a
 * per-request bootstrap such as PHP-FPM.
 *
 * **'cache' mode**: no stream wrapper. Translated files are written to the cache directory
 * *mirroring the original absolute path* (`<cache>/<original absolute path>`), real files that
 * opcache can and does cache normally, like any other file. {@see path()} resolves a path the way
 * PHP's own include would, translates it if needed and returns the path to open instead; it is
 * also what phasync-babel's `includeHandler` option rewrites every include/require to call, so
 * that a translated file's own includes are translated too, recursively, without re-registering
 * anything. phasync-babel's `fileConstants` option keeps __FILE__/__DIR__ reporting the *original*
 * path even though the file PHP actually compiled is the cache mirror; Reflection's
 * getFileName(), an exception's getFile()/getTrace() and PHP's own error messages, by contrast,
 * necessarily show the mirror path (mirroring the original tree, so still recognisable), since
 * those come from the file PHP actually opened, which this mode does not hide. The entry point
 * (swerve.php) requires the application's own entry through {@see path()} explicitly; every file
 * it requires (and everything *that* requires) stays in the cache once a translated file's own
 * requires are rewritten into it by phasync-babel, so no second entry point is needed.
 *
 * In both modes: a path under one of the excluded prefixes (phasync, swerve, phasync-babel,
 * phasync-hooked and phasync-plug's own src/) is served from its original path, untranslated --
 * this loader must never translate the libraries that do the translating or already run
 * natively against phasync. Translated source is cached on disk, keyed by the file's real path,
 * mtime and size, and a hash of the map and phasync/babel's own version/reference, so a file is
 * translated once -- not per request, and not per worker, since the cache is shared on disk by
 * every worker and every process that uses the same cache directory.
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

    /** 'wrapper' or 'cache', see install(). */
    private static string $mode = 'wrapper';

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
     * either registers this class as the 'file' stream wrapper ('wrapper' mode, the default) or
     * prepares the on-disk cache mirror {@see path()} resolves into ('cache' mode).
     *
     * @param array{functions?: array<string,string>, classes?: array<string,string>} $map      phasync-babel's map (phasync/hooked's src/map.php, or a subset of it)
     * @param string                                                                  $cacheDir  directory for cached translations, created if missing; shared safely by several workers/processes (translate-then-atomic-rename)
     * @param list<string>                                                            $excludePrefixes path prefixes never translated (phasync, swerve, phasync-babel, phasync-hooked, phasync-plug); symlinks are resolved
     * @param 'wrapper'|'cache'                                                       $mode      'wrapper': a file:// stream wrapper, original paths preserved by PHP believing it opened them; 'cache': no wrapper, a cache directory mirroring the original tree, opcache-cacheable, original paths preserved by phasync-babel's fileConstants/includeHandler options instead
     */
    public static function install(array $map, string $cacheDir, array $excludePrefixes, string $mode = 'cache'): void
    {
        if (self::$installed) {
            throw new \LogicException(self::class . '::install() runs once per process');
        }
        if ('wrapper' !== $mode && 'cache' !== $mode) {
            throw new \InvalidArgumentException(self::class . "::install(): mode must be 'wrapper' or 'cache', got '$mode'");
        }
        self::$mode = $mode;
        if ('cache' === $mode) {
            // Cache mode's own trade-off (see this class's docblock): Reflection, not rewritten
            // by fileConstants the way __FILE__/__DIR__ are, reports the cache *mirror* path --
            // a tree of translated .php files only, missing every non-PHP resource (config/,
            // templates/, ...) a framework's own Reflection-based self-location (Symfony's
            // Kernel::getProjectDir(), say) would otherwise find right next to it. These
            // Reflection subclasses and four function replacements (see Reflection/*.php and
            // functions.php) map every one of those back to the original path babel's own
            // fileConstants rewrite already uses -- built in here, not left to every caller to
            // remember, since it is intrinsic to what 'cache' mode must guarantee.
            //
            // ReflectionMethod is deliberately NOT in this map, unlike the others: confirmed
            // against a real app (swerve-symfony's own compiler pass,
            // AutowireRequiredMethodsPass) that ReflectionClass::getMethods() returns *native*
            // ReflectionMethod instances regardless of this map (it is not itself overridden),
            // so a parameter elsewhere babel also rewrote to this namespace's ReflectionMethod
            // type then rejects them with a TypeError -- a native instance is not an instance of
            // a subclass. ReflectionMethod::getFileName() keeps reporting the mirror path as a
            // result.
            //
            // ReflectionClass itself has the same exposure through getParentClass()/
            // getInterfaces()/getTraits() -- also native-returning -- confirmed against the same
            // app (Config\Resource\ReflectionClassResource::loadFiles() recurses through exactly
            // these three). Unlike ReflectionMethod, these three are common enough (any class-
            // hierarchy walk) that WrapsClassAccessors wraps their results back into this
            // namespace's ReflectionClass instead of excluding the class outright. Other native
            // APIs returning a bare \ReflectionClass (ReflectionProperty::getDeclaringClass(),
            // ReflectionFunctionAbstract::getClosureScopeClass(), ...) are not wrapped and remain
            // the same kind of residual risk, not found to matter in any suite run here.
            require_once __DIR__ . '/Reflection/UnmirrorsFileName.php';
            require_once __DIR__ . '/Reflection/WrapsClassAccessors.php';
            require_once __DIR__ . '/Reflection/ReflectionClass.php';
            require_once __DIR__ . '/Reflection/ReflectionObject.php';
            require_once __DIR__ . '/Reflection/ReflectionEnum.php';
            require_once __DIR__ . '/Reflection/ReflectionFunction.php';
            require_once __DIR__ . '/functions.php';
            $map['classes'] = ($map['classes'] ?? []) + [
                \ReflectionClass::class => Reflection\ReflectionClass::class,
                \ReflectionObject::class => Reflection\ReflectionObject::class,
                \ReflectionEnum::class => Reflection\ReflectionEnum::class,
                \ReflectionFunction::class => Reflection\ReflectionFunction::class,
            ];
            $map['functions'] = ($map['functions'] ?? []) + [
                'get_included_files' => __NAMESPACE__ . '\\get_included_files',
                'get_required_files' => __NAMESPACE__ . '\\get_required_files',
                'debug_backtrace' => __NAMESPACE__ . '\\debug_backtrace',
                'debug_print_backtrace' => __NAMESPACE__ . '\\debug_print_backtrace',
            ];
        }
        self::$babel = 'cache' === $mode
            ? new Babel(functions: $map['functions'] ?? [], classes: $map['classes'] ?? [], includeHandler: 'phasync\\plug\\path', fileConstants: true)
            : new Babel(functions: $map['functions'] ?? [], classes: $map['classes'] ?? []);
        Runtime::configure(self::$babel);

        self::$cacheDir = \rtrim($cacheDir, '/');
        if (!\is_dir(self::$cacheDir) && !@\mkdir(self::$cacheDir, 0777, true) && !\is_dir(self::$cacheDir)) {
            throw new \RuntimeException(self::class . "::install(): cannot create cache directory $cacheDir");
        }

        self::$exclude = [];
        foreach ($excludePrefixes as $prefix) {
            $real = \realpath($prefix);
            if (false === $real) {
                throw new \RuntimeException(self::class . "::install(): exclude prefix does not exist: $prefix");
            }
            self::$exclude[] = \rtrim($real, '/') . '/';
        }

        self::$babelVersion = self::resolveBabelVersion();

        if ('wrapper' === $mode) {
            \stream_wrapper_unregister('file');
            \stream_wrapper_register('file', self::class);
        }
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

    /** The cache key for $real's current content: its real path, mtime, size, the map and babel's version -- shared by both cache layouts. $stat: stat()'s result, or false when it failed. */
    private static function cacheKey(string $real, array|false $stat): string
    {
        return \hash('sha256', $real . "\0" . ($stat['mtime'] ?? 0) . "\0" . ($stat['size'] ?? 0) . "\0" . self::$babel->fingerprint . "\0" . self::$babelVersion);
    }

    /**
     * $src translated through phasync/babel, from the on-disk cache when a cached translation of
     * the same real path, mtime, size, map and babel version already exists. 'wrapper' mode's
     * cache: one flat file per key (the key is unique per content, so an existing file is always
     * valid -- stale keys are simply orphaned, never cleaned up).
     */
    private static function translateCached(string $real, string $src): string
    {
        $stat = self::native(static fn () => @\stat($real));
        $cacheFile = self::$cacheDir . '/' . self::cacheKey($real, $stat) . '.php';

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

    /**
     * 'cache' mode: $real's translation, as a real file at the cache's mirror of $real
     * (`<cacheDir><real>`), written only when missing or the key (see cacheKey()) changed, since
     * a key does not name the file here -- the path must mirror $real exactly, so a sibling
     * `.key` file records which translation is currently there. No stream wrapper is involved in
     * this mode, so these are plain native calls.
     */
    private static function cacheMirrorFor(string $real): string
    {
        $real = self::unmirror($real);
        $mirror = self::$cacheDir . $real;
        if (self::excluded($real)) {
            return $real; // untranslated: no mirror, open the original directly
        }
        $stat = @\stat($real);
        $key = self::cacheKey($real, $stat);
        $keyFile = $mirror . '.key';
        if (\is_file($mirror) && @\file_get_contents($keyFile) === $key) {
            return $mirror;
        }
        $src = @\file_get_contents($real);
        if (false === $src) {
            throw new \RuntimeException(self::class . "::cacheMirrorFor(): cannot read $real");
        }
        $out = self::$babel->translate($src, $real);
        $dir = \dirname($mirror);
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(self::class . "::cacheMirrorFor(): cannot create cache directory $dir");
        }
        $unique = \getmypid() . '.' . \bin2hex(\random_bytes(4));
        \file_put_contents($tmp = "$mirror.$unique.tmp", $out);
        \rename($tmp, $mirror); // atomic on the same filesystem; a concurrent writer overwrites with identical bytes
        \file_put_contents($tmp = "$keyFile.$unique.tmp", $key);
        \rename($tmp, $keyFile); // written after $mirror: a reader never sees a key that doesn't match what's already there

        return $mirror;
    }

    /**
     * $real (or $fromDir), with any leading cache-mirror prefix stripped back to the original
     * absolute path it mirrors -- a no-op unless $real is already inside the mirror tree.
     *
     * 'cache' mode never hides the mirror path from Reflection's getFileName(), an exception's
     * getFile()/trace, or PHP's own error messages (see install()'s docblock: only __FILE__ and
     * __DIR__, via fileConstants, report the original). Code that locates itself through one of
     * those -- Symfony's compiled DI container does, to find a sibling file -- and builds a path
     * from it (`dirname($reflectedFile) . '/Sibling.php'`) therefore builds a path that is
     * *already* `<cacheDir><original sibling path>`: dirname() of the mirror plus the sibling's
     * own name reconstructs the mirror's own path to it. Resolving that again, unchanged, would
     * mirror the mirror (`<cacheDir><cacheDir><original sibling path>`) -- a second, distinct
     * file defining the same classes/interfaces as the first, so the second require fatals with
     * "Cannot redeclare". Stripping the prefix first makes it resolve to the exact same mirror
     * the original path already produced, loaded (at most) once.
     *
     * A loop, not a single strip: idempotent against more than one level of nesting however it
     * might arise, so the Loader never mirrors a mirror, full stop, not just this one level.
     *
     * Public: also what the Reflection subclasses and function replacements in cache mode's own
     * map (see install()) call to turn a mirror path back into the original one.
     */
    public static function unmirror(string $real): string
    {
        $prefix = self::$cacheDir . '/';
        while (\str_starts_with($real, $prefix)) {
            $real = \substr($real, \strlen(self::$cacheDir));
        }

        return $real;
    }

    /** The cache directory in use, for code (debug_print_backtrace()'s replacement) that strips its text out of a whole blob rather than one path at a time. */
    public static function cacheDir(): string
    {
        return self::$cacheDir;
    }

    /**
     * 'cache' mode: $path resolved exactly as PHP's own include/require would from $fromDir
     * (absolute as given; './'/'../' relative to the working directory, include_path ignored;
     * otherwise each include_path entry, then $fromDir, then the working directory), or false
     * when nothing exists at any candidate.
     */
    private static function resolveIncludePath(string $path, string $fromDir): string|false
    {
        if ('' !== $path && ('/' === $path[0] || \str_starts_with($path, './') || \str_starts_with($path, '../'))) {
            $candidate = '/' === $path[0] ? $path : \rtrim((string) \getcwd(), '/') . '/' . $path;

            return \is_file($candidate) ? (\realpath($candidate) ?: $candidate) : false;
        }
        $includePath = (string) \ini_get('include_path');
        $dirs = '' === $includePath ? [] : \explode(\PATH_SEPARATOR, $includePath);
        foreach ([...$dirs, $fromDir, (string) \getcwd()] as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = \rtrim($dir, '/') . '/' . $path;
            if (\is_file($candidate)) {
                return \realpath($candidate) ?: $candidate;
            }
        }

        return false;
    }

    /**
     * 'cache' mode's one entry point for opening a path the way PHP's own include/require would:
     * resolves $path from $fromDir, translates it if needed (unless excluded) and returns the
     * real path to require/include instead -- the cache mirror, or the original path when
     * excluded or already not needing translation. This is also what phasync-babel's
     * includeHandler rewrites every include/require in a translated file to call, with its own
     * directory as $fromDir, so a translated file's own includes are translated too, recursively.
     *
     * @see path() the plain function phasync-babel's includeHandler is configured with
     *
     * @throws \RuntimeException when nothing exists at $path (from $fromDir)
     */
    public static function resolve(string $path, string $fromDir): string
    {
        if ('cache' !== self::$mode) {
            throw new \LogicException(self::class . "::resolve(): install() was not called with mode: 'cache'");
        }
        $real = self::resolveIncludePath(self::unmirror($path), self::unmirror($fromDir));
        if (false === $real) {
            throw new \RuntimeException(self::class . "::resolve(): nothing at '$path' (from '$fromDir')");
        }

        return self::cacheMirrorFor($real);
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
