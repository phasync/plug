# phasync/plug

An end-to-end proof: how closely Swerve **without** ext-phasync, but with application code
translated by [phasync-babel](https://github.com/) to call
[phasync-hooked](https://github.com/)'s replacements, matches Swerve **with** ext-phasync loaded,
on a real (if small) application. Not published; local proof only.

## `phasync\plug\Loader`

A `file://` stream wrapper, registered by the one entry point `Loader::install(array $map, string
$cacheDir, array $excludePrefixes)`. Every file PHP opens for include is translated through
phasync-babel and served under its *original* path (`__FILE__`, `__DIR__`, `include_once`
dedup, error messages and stack traces all read as without the wrapper -- the same mechanism
phasync-babel's own test harness, `tests/IncludeWrapper.php`, uses). Every other file operation
(`file_get_contents`, `fopen`, `is_file`, `mkdir`, `unlink`, `touch`, `rename`, `opendir`, `stat`,
...) passes straight through to the native wrapper.

Translations are cached on disk at `$cacheDir`, keyed by the file's real path, mtime, size, and a
hash of the map plus phasync-babel's own installed reference -- a file is translated once, read
back from the cache by every later worker or process that shares the directory.

`$excludePrefixes` are symlink-resolved and never translated: in this proof, phasync, swerve,
phasync-babel, phasync-hooked and phasync-plug's own `src/` (see "What didn't work" below for why
this list is not optional).

### Tests

`vendor/bin/pest` (6 tests, all passing). Since `Loader::install()` runs once per process (it
registers a process-global stream wrapper), each scenario runs `tests/fixtures/harness.php` as
its own subprocess. Covered: `__FILE__`/`__DIR__` preserved under translation;
`include_once`/`require_once` dedup across translated files; a path under an excluded prefix
served byte-for-byte unchanged; the on-disk cache is reused by a second, independent process
without rewriting the cache file; native file operations unaffected; `install()` refuses a second
call in the same process.

## The opcache finding

**PHP's opcache never caches a script opened through a non-default `'file'` stream wrapper.**
Confirmed empirically (`research/opcache-probe.php`, included and runnable): a trivial
pass-through wrapper -- same mechanism as `Loader`, minus the translation -- registered for
`'file'`, with the *same* target file included three times in one process:

| | `scripts` in `opcache_get_status(true)` | hits | every include |
|---|---|---|---|
| native (no wrapper) | file listed, cached | 2 of 3 | 1 compile, 2 served from shared memory |
| custom `'file'` wrapper | **never listed** | **0** | **recompiled from source, every time** |

No combination of `opcache.enable_file_override`, `opcache.validate_timestamps`, declaring
`stream_set_option()`, or `url_stat()`/`stream_stat()` restores caching for the wrapper case --
tried all four, same result every time. This matches OPcache's own architecture: its shared-memory
cache is keyed to files opened through the engine's built-in "plain files" wrapper specifically;
replacing the `'file'` protocol handler, which is exactly what translating code under its original
path requires, opts every file touched by that wrapper out of OPcache for good. Patchwork and
dg/bypass-finals, which use the same kind of wrapper for a different purpose (monkey-patching,
stripping `final`), hit the same wall and don't claim to solve it either; there is no userland
trick that gets both the original path *and* opcode caching for a file opened this way -- the two
requirements are architecturally exclusive.

**Why this doesn't block Swerve, but would block PHP-FPM.** Swerve's worker model requires
application code once, at boot, and keeps it loaded for the worker's life -- so this cost is paid
once per worker start, not per request (confirmed below: `/cpu` latency with the loader on is
indistinguishable from off, once the worker has booted). Under a per-request bootstrap such as
PHP-FPM, every request would recompile the entire translated call graph from source on every hit,
losing exactly the acceleration opcache exists to provide -- a severe regression there. This is
the deciding factor on production viability the task asked for: **viable for a Swerve-shaped,
boot-once server; not viable for a per-request one.**

(An artifact worth noting from building the probe itself: `opcache.file_update_protection`, 2
seconds by default, skips caching any file younger than that -- a file created and included in the
same request is never cached either way, wrapper or not, confounding a naive version of this test.
Swerve already sets it to `0` in its own ini defaults, so the finding above is not an artifact of
that setting.)

## The test application (`e2e/app/`)

A plain `Swerve\RequestHandler`, no framework, with Predis installed via Composer:

- `/sleep` -- `sleep(1)`, returns `"slept"`;
- `/redis` -- Predis `BLPOP` on an empty key with a 1s timeout (a blocking server-side wait; a
  fresh connection per request -- see "What didn't work" below for why sharing one doesn't work);
- `/proc` -- `exec('sleep 1; echo hi', $out)`, returns the output;
- `/cpu` -- a short pure-PHP loop, the control (not I/O).

`e2e/app/swerve.php` is a thin bootstrap: it installs the loader (when `PLUG_LOADER_ON=1`) with
phasync-hooked's map, then `require`s `app.php` -- the actual application -- as a *separate* file
(see "What didn't work": a file cannot translate its own source). `app.php` and everything it
requires, Predis included, are then compiled through the wrapper when the loader is on.

## Results

Three modes, one Swerve worker each (`--workers=1`), identical app and routes. Redis ran on
`127.0.0.1:16399`; A/B/C each on their own port (18600/18601/18602).

| Mode | `/sleep` (10 concurrent) | `/redis` (10 concurrent) | `/proc` (10 concurrent) | responses identical |
|---|---|---|---|---|
| A: no ext, loader off | 10.04 s | 10.86 s | 10.08 s | yes |
| B: no ext, loader on (the plug) | **1.03 s** | **1.06 s** | **1.04 s** | yes |
| C: ext-phasync loaded, loader off | **1.02 s** | 1.10 s | 1.03 s | yes |

A is sequential (10 requests x ~1s, one after another: the worker blocks on each native call).
B and C both collapse to ~1 request's worth of wall time: the ten waits run concurrently. B tracks
C within measurement noise on every route. Every response body was identical across all three
modes, for every route (`/sleep` → `slept`, `/proc` → `hi`, `/redis` → `redis-timeout`, `/cpu` →
`599994`).

`/cpu`, 200 sequential requests, average latency once the worker is warm (the loader's own
steady-state overhead, since there is no `include` left on the request path by then):

| Mode | avg latency |
|---|---|
| A: loader off | 18.46 ms |
| B: loader on | 18.63 ms |

A ~0.2 ms difference on a 18 ms baseline (~1%), consistent with no translation happening on the
request path at all -- the loader's cost is paid once, at worker boot, not per request.

## What didn't work, and why

- **The entry file cannot translate its own source.** PHP compiles an entire file before running
  any of its statements, so `Loader::install()` can never affect the file it is called from --
  calling it as `swerve.php`'s first statement, with `sleep()`/Predis calls written directly in
  that same file, left every one of those calls untranslated (confirmed: mode B measured the same
  ~10s as mode A until this was fixed). Resolved by splitting `swerve.php` into a thin bootstrap
  (install, then `require 'app.php'`) and moving the actual routes into `app.php`, a separate file
  required only after installation.

- **`phasync-babel` must exclude its own path, or class loading breaks.** `Babel::translate()`
  lazily autoloads its own classes (`phasync\Babel\Unit`, ...) through Composer; if phasync-babel's
  own path isn't in `$excludePrefixes`, that `require` is re-fed into `translateCached()` ->
  `Babel::translate()` on the *same, not-reentrant* `Babel` instance, and the class never finishes
  defining (`Class "phasync\Babel\Unit" not found`). Confirmed and reproduced in
  `tests/fixtures/harness.php`'s own history; it now always excludes phasync-babel's path, which is
  also why the task's exclusion list is a correctness requirement, not just tidiness.

- **An exclude prefix must name the library, not a directory that also holds translated code.**
  `e2e/app` lives inside `phasync-plug`'s own repository tree (`phasync-plug/e2e/app`), purely for
  this proof's convenience. An early version excluded all of `~/dev/phasync-plug` (meant to keep
  `src/Loader.php` untranslated) and silently excluded the entire test application along with it --
  every route "worked" but nothing was ever translated, and the loader had no effect. Fixed by
  excluding `phasync-plug/src` specifically, not the whole repository.

- **One blocking connection cannot be shared by concurrent coroutines.** The first version of
  `app.php` built one `Predis\Client` at worker boot and reused it for every request. Under real
  concurrency (mode B/C, ten `/redis` requests at once) this threw
  `stream_set_blocking(): ... must be an open stream resource` from inside phasync-hooked, because
  two coroutines were driving the same TCP connection's buffer at the same time. Not a Loader or
  hooked bug: no async Redis client supports concurrent commands over one connection without
  pipelining, which Predis here doesn't use. Fixed by opening a connection per request in `/redis`;
  the loader still only translates Predis's files once, at first use.

- **A stray `dirname()` depth silently produced a nonexistent exclude path**, which this project's
  `Loader::install()` now treats as a hard error (`realpath()` fails -> throws) rather than
  silently exempting nothing, which is what the first version did and which is how the previous
  two findings above went unnoticed for a while.

Everything else in the task went as the plan expected: Predis's own `fread`/`fwrite`/`fgets`/
`stream_socket_client` calls are confirmed translated (its files appear in the on-disk translation
cache, 24 files after a cold boot of mode B); `--ext` loaded phasync 2.0.4's bundled extension via
`PHP_INI_SCAN_DIR` with no changes needed to swerve or phasync; redis-server and every Swerve
instance were started with a PID file and stopped by PID, nothing left running.
