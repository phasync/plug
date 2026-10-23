# phasync/plug

Translates whole PHP applications, file trees rather than single files, with
[phasync/babel](https://github.com/phasync/babel), so that their calls to blocking PHP functions
(`fopen`, `fread`, `sleep`, `session_start`, ...) go to the cooperative replacements in
[phasync/hooked](https://github.com/phasync/hooked). babel translates PHP code; plug applies it to
an application as PHP loads it. Together they do in userspace what ext-phasync does natively
(non-blocking I/O, per-request `sapi()` state), so Swerve can run applications cooperatively
without the extension.

The measurements below compare Swerve without ext-phasync (plug + babel + hooked) against Swerve
with the extension on a real, if small, application.

## `phasync\plug\Loader`

The one entry point is `Loader::install(array $map, string $cacheDir, array $excludePrefixes,
string $mode = 'cache')`, in one of two modes:

**`'wrapper'`**. `Loader` is itself a `file://` stream wrapper: every file PHP opens
for include is translated through phasync-babel and served under its *original* path (`__FILE__`,
`__DIR__`, `include_once` dedup, error messages and stack traces all read as without the wrapper
-- the same mechanism phasync-babel's own test harness, `tests/IncludeWrapper.php`, uses). Every
other file operation (`file_get_contents`, `fopen`, `is_file`, `mkdir`, `unlink`, `touch`,
`rename`, `opendir`, `stat`, ...) passes straight through to the native wrapper. Simple, and the
original mode this proof was built around -- but see "The opcache finding" below for the real cost
it carries.

**`'cache'`** (the default). No stream wrapper at all. Translated files are written to the cache directory
*mirroring the original absolute path* (`<cache>/<original path>`): real files, which opcache
caches exactly as it would any other file. `phasync\plug\path($path, $fromDir)` resolves `$path`
the way PHP's own `include` would (absolute; `./`/`../` relative to the working directory;
otherwise `include_path` entries, then `$fromDir`, then the working directory), translates it if
needed and returns the path to actually open. This is also the one function phasync-babel's new
`includeHandler` option is configured with in this mode (its new `fileConstants` option keeps
`__FILE__`/`__DIR__` reporting the original path), so a translated file's own `include`/`require`
statements are rewritten to call it too -- recursively, with no second entry point needed.
Reflection's `getFileName()`, an exception's `getFile()`/trace, and PHP's own error messages,
unlike `__FILE__`/`__DIR__`, do show the cache mirror path in this mode (still recognisable: it
mirrors the original tree), since those necessarily come from the file PHP actually compiled.

Both modes share the same on-disk cache semantics: translations are keyed by the file's real path,
mtime, size, and a hash of the map plus phasync-babel's own installed reference, so a file is
translated once, read back by every later worker or process that shares the directory.
`$excludePrefixes` are symlink-resolved and never translated in either mode: in this proof,
phasync, swerve, phasync-babel, phasync-hooked and phasync-plug's own `src/` (see "What didn't
work" below for why this list is not optional).

### Tests

`vendor/bin/pest` (16 tests, all passing). Since `Loader::install()` runs once per process (in
`'wrapper'` mode it registers a process-global stream wrapper), each scenario runs
`tests/fixtures/harness.php` as its own subprocess, in either mode. Covered, in both modes:
`__FILE__`/`__DIR__` preserved under translation and the hooked replacement actually called;
a mapped `PHP_SAPI` constant reads the real SAPI outside a `\Scope` override and the set name
inside one (phasync-babel's constant-to-call mapping plus phasync-hooked's `php_sapi()`/
`set_sapi_name()`, end to end -- the same `'constants'` map entry `e2e/app/swerve.php` uses for
real); `include_once`/`require_once` dedup across translated files; a path under an excluded
prefix served byte-for-byte unchanged; the on-disk cache is reused by a second, independent
process without rewriting it. `'cache'`-mode only: the mirror file is confirmed cached by opcache
(`opcache_get_status(true)`, 2 hits of 3 includes). Mode-agnostic: native file operations
unaffected (no wrapper exists in `'cache'` mode either, so this is trivially true there, checked
once more anyway); `install()` refuses a second call in the same process.

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
requirements are architecturally exclusive *for that mechanism*. `Loader`'s `'cache'` mode sidesteps
the conflict by not using a stream wrapper at all (see above): a real file at a real (mirror) path,
confirmed opcache-cacheable the normal way.

**Why this doesn't block Swerve, but would block PHP-FPM -- for files required once at boot.**
Swerve's worker model requires application code once, at boot, and keeps it loaded for the
worker's life -- so for files only ever required that way (an application's own entry point, its
classes), the recompile cost is paid once per worker start, not per request (confirmed below:
`/cpu` latency with the loader on is indistinguishable from off, once the worker has booted). Under
a per-request bootstrap such as PHP-FPM, those same files would recompile on every hit instead.

**A file `include`d fresh on every request (compiled views, config and route files, a classic
front controller and its templates) pays the recompile cost on every request in `'wrapper'`
mode, and that cost is not small.** Measured without opcache, compiling only (no execution): a
31 KB template with PHP in every row compiles in 0.59 ms (about 19 µs per KB); a 120 KB file of
400 classes compiles and declares in 6 ms. A request that includes 100 KB of compiled views pays
milliseconds of recompilation per request through the wrapper. The `/tpl` comparison below does
not show it because its templates compile cheaply and its ~15 ms per request is dominated by
rendering a 191 KB page; the differences between modes are within that run's noise. (An earlier
version of this section claimed a 20,000-line file recompiles in ~0.06 ms; that probe measured a
cached path, not compilation, and the claim is withdrawn.) **Conclusion: `'cache'` mode is the
default, because opcache must cache translated files like any other. `'wrapper'` mode remains for
debugging, where original paths in traces matter more than speed.**

(Two artifacts worth noting from building the probes: `opcache.file_update_protection`, 2 seconds
by default, skips caching any file younger than that -- a file created and included in the same
request is never cached either way, wrapper or not, confounding a naive version of the first test
until it was set to `0`; Swerve already sets it to `0` in its own ini defaults, so the opcache
finding above is not an artifact of that setting. Separately, the recompile-cost probe's naive
first version timed `include` of a 300-loop-per-row template and found opcache-cached *slower*
than never-cached, by over 1 ms -- because `include`'s own time includes *running* the loops, not
just compiling them, and that runtime cost is identical either way and dominated the measurement;
isolating compile time needed files with many simple statements and no runtime work.)

## The test application (`e2e/app/`)

A plain `Swerve\RequestHandler`, no framework, with Predis installed via Composer:

- `/sleep` -- `sleep(1)`, returns `"slept"`;
- `/redis` -- Predis `BLPOP` on an empty key with a 1s timeout (a blocking server-side wait; a
  fresh connection per request -- see "What didn't work" below for why sharing one doesn't work);
- `/proc` -- `exec('sleep 1; echo hi', $out)`, returns the output;
- `/cpu` -- a short pure-PHP loop, a control (not I/O, nothing `include`d per request);
- `/tpl` -- renders a page from 20 small template files (`e2e/app/templates/tpl-*.php`, ~245 lines
  each: HTML mixed with `<?= ?>` and `foreach` loops, like a compiled view), each freshly
  `include`d (not `_once`) on *every* request -- a second control, this one with 20 real
  `include`s on the request path every time, every mode.

`e2e/app/swerve.php` is a thin bootstrap: it installs the loader (when `PLUG_LOADER_ON=1`, in the
mode named by `PLUG_MODE`) with phasync-hooked's map, then requires `app.php` -- the actual
application -- as a *separate* file (see "What didn't work": a file cannot translate its own
source), through `phasync\plug\path()` in `'cache'` mode (no stream wrapper exists there) or
plainly otherwise. `app.php` and everything it requires, Predis and the templates included, are
then compiled through the loader when it is on, in whichever mode.

## Results

Four modes, one Swerve worker each (`--workers=1`), identical app and routes. Redis ran on
`127.0.0.1:16399`; each mode on its own port (18600-18603).

| Mode | `/sleep` (10 concurrent) | `/redis` (10 concurrent) | `/proc` (10 concurrent) | responses identical |
|---|---|---|---|---|
| A: no ext, loader off | 10.03 s | 10.86 s | 10.08 s | yes |
| B-wrapper: no ext, loader on, `'wrapper'` | **1.03 s** | **1.06 s** | **1.04 s** | yes |
| B-cache: no ext, loader on, `'cache'` | **1.03 s** | **1.11 s** | **1.04 s** | yes |
| C: ext-phasync loaded, loader off | **1.02 s** | 1.10 s | 1.03 s | yes |

A is sequential (10 requests x ~1s, one after another: the worker blocks on each native call).
Every other mode collapses to ~1 request's worth of wall time: the ten waits run concurrently,
indistinguishably between B-wrapper, B-cache and C -- including `/redis`, which needs Predis's own
files translated too (see "What didn't work": this did not work on the first attempt in `'cache'`
mode). Every response body was identical across every mode, for every route (`/sleep` → `slept`,
`/proc` → `hi`, `/redis` → `redis-timeout`, `/cpu` → `599994`, `/tpl` → the same 191205-byte page,
same MD5, in all four modes).

**The per-request cost** -- `/tpl` (20 fresh `include`s/request, 200 sequential, averaged once the
worker is warm) and, for comparison, `/cpu` (0 `include`s/request, the original control):

| Mode | `/tpl` avg latency | `/cpu` avg latency |
|---|---|---|
| A: loader off | 15.82 ms | 18.46 ms |
| B-wrapper | 14.75 ms | 18.63 ms |
| B-cache | 17.39 ms | -- |
| C: ext-phasync | 15.49 ms | -- |

All four `/tpl` numbers sit within about 3 ms of each other, which is this run's noise; the
route's templates compile too cheaply, relative to the 191 KB page they render, to show the
recompile cost (see "The opcache finding" for the measured compile costs). B-cache's slightly
higher number is plausibly the per-include `stat()` and `.key` read `path()` does even on a cache
hit; an in-process memo of resolved paths would remove it. `/cpu`'s ~0.2 ms A-vs-B gap (unchanged from the
original three-mode run) remains the loader's one confirmed per-request cost, and it has nothing
to do with `include`: the loader does zero include-related work on `/cpu`'s request path, in
either mode.

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

- **`'cache'` mode's bootstrap must never load Composer's own autoloader natively, or everything
  it later autoloads stays untranslated for good.** `'cache'` mode rewrites `include`/`require`
  only in a file's own source, at translate time -- a file compiled before the loader existed
  keeps calling the *native*, unrewritten `require` for the rest of the process, no matter when
  the loader is installed afterward. `'wrapper'` mode has no such gap (it intercepts every
  `require` at the stream level, dynamically, checked at the moment each one actually runs, not
  baked in when the requiring code was compiled) -- which is exactly why this was only found once
  `'cache'` mode existed to compare against: the very first version of `swerve.php`'s `'cache'`
  bootstrap still did `require 'vendor/autoload.php';` natively (to make `Loader`/`Babel`
  autoloadable at all), which loads Composer's `ClassLoader` natively too -- and *every* class it
  autoloads afterward, Predis included, calls `ClassLoader::loadClass()`'s own `require $file`,
  compiled once, natively, when `ClassLoader.php` first loaded. Confirmed: 10 concurrent `/redis`
  took ~10s in `'cache'` mode, not ~1s, with none of Predis's files appearing in the cache mirror
  at all. Fixed by having `swerve.php` require only phasync-plug's and phasync-babel's own files
  directly, by their known paths, instead of Composer's autoloader -- the *first* `require` of
  `vendor/autoload.php` now happens inside `app.php`, which *is* translated, so Composer's own
  `require($file)` calls are rewritten too, and everything it then autoloads (Predis included)
  is reached, recursively, exactly as `'wrapper'` mode reaches it by a different route. One more
  wrinkle this surfaced: phasync-plug's `path()` is a Composer "files" entry (always required
  unconditionally, unlike a class, which an autoloader skips once already declared), so
  pre-requiring it naively caused `Cannot redeclare function phasync\plug\path()` once Composer's
  own autoloader loaded it again, normally, later; fixed with a `function_exists()` guard around
  its declaration, the same idiom packages with "files" entries meant to tolerate a second load
  already use.

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
  two findings above went unnoticed for a while. The same mistake recurred once more, writing the
  opcache Pest test for `'cache'` mode (`dirname(__DIR__, 2)` from `tests/`, one level too many) --
  caught immediately this time, by the same hard error, in seconds rather than an investigation.

- **A naive recompile-cost probe measured runtime, not compile time.** Timing `include` of a
  template with 300 loop iterations per "row" found opcache-cached *slower* than never-cached --
  because `include` always both compiles and *runs* a file, and the loops' own execution time (the
  same either way) dominated the measurement. Isolating compile time needed files built from many
  simple statements doing no work at all; a template-generator script bug (a bash variable escaped
  where it should have substituted, left `$row` undefined) surfaced the same lesson again in the
  actual `/tpl` templates -- caught by `php -l` and a PHP warning in the raw output, not a design
  flaw in the loader.

Everything else in the task went as the plan expected: Predis's own `fread`/`fwrite`/`fgets`/
`stream_socket_client` calls are confirmed translated in both modes (21 of Predis's files appear in
`'cache'` mode's mirror tree; 24 files in `'wrapper'` mode's flat cache after a cold boot); `--ext`
loaded phasync 2.0.4's bundled extension via `PHP_INI_SCAN_DIR` with no changes needed to swerve or
phasync; redis-server and every Swerve instance were started with a PID file and stopped by PID,
nothing left running.
