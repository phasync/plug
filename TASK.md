# Task: phasync-plug, the end-to-end proof

Goal: show how closely Swerve **without** ext-phasync, but with application code translated by
phasync-babel to call phasync-hooked's replacements, matches Swerve **with** ext-phasync, on a real
application. Local repo `~/dev/phasync-plug` (package `phasync/plug`, namespace `phasync\plug`);
never push or publish anything.

Read first: `~/dev/phasync-babel/README.md` (API, the stream-wrapper loading model, limitations),
`~/dev/phasync-hooked/README.md` and `src/map.php`, `~/dev/swerve/README.md` (swerve.php entry,
`--ext`, workers), and how swerve's worker loads application code (`~/dev/swerve/bin/swerve.php`,
`src/Util/WorkerAutoloader.php`). Use babel and hooked as Composer path repositories.

## 1. The loader (`phasync\plug\Loader`)

A `file://` stream wrapper that translates PHP files as PHP opens them for include
(`STREAM_OPEN_FOR_INCLUDE` in the open options) and passes every other operation through to the
native file wrapper (restore/re-register around native calls, as babel's `tests/IncludeWrapper.php`
does). Requirements:
- translated code runs under its original path (`__FILE__`, `__DIR__`, include_once, errors, traces);
- an exclusion list of path prefixes never translated: phasync, swerve, phasync-babel,
  phasync-hooked, phasync-plug itself (all their real paths, symlinks resolved);
- translations cached on disk (key: real path, mtime, size, a hash of the map and babel's version),
  so a file is translated once, not per request or per worker;
- `Loader::install(array $map, string $cacheDir, array $excludePrefixes)` is the one entry point,
  and it also calls `phasync\Babel\Runtime::configure()`;
- **opcache**: find out empirically whether opcache caches scripts included through this wrapper
  (`opcache_get_status(true)['scripts']`, and timing a repeated include). If it doesn't, find a way
  that keeps original paths and lets opcache cache (look at `stream_set_option`/`url_stat`/
  `stream_stat` and `opcache.enable_file_override`, and how projects like Patchwork or
  dg/bypass-finals handle it). Report the outcome with evidence; this decides whether the approach
  is viable for production.

Tests (Pest, first): include/require/_once of translated files with correct `__FILE__`/`__DIR__`,
excluded paths untouched, cache hits after the first include, non-include file operations
(file_get_contents, fopen, is_file, mkdir, unlink, touch, rename, opendir, stat) behave natively
through the wrapper, and a translated file actually calls the hooked replacement.

## 2. The test application and how Swerve loads it

A small swerve.php application (plain `Swerve\RequestHandler`, no framework) in `e2e/app/` with
Predis installed via Composer, and routes:
- `/sleep`: `sleep(1)`, returns "slept";
- `/redis`: Predis `BLPOP` on an empty key with a 1 s timeout (a blocking server-side wait), returns
  the result;
- `/proc`: `exec('sleep 1; echo hi', $out)`, returns the output;
- `/cpu`: a short pure-PHP loop, returns a number (the control: not I/O).

`swerve.php` installs the loader as its first statement (with phasync-hooked's map), then loads the
application so that every application and vendor file (Predis!) goes through the loader. Check that
Predis's own files are actually translated (its `fread`/`fwrite`/`fgets`/`stream_socket_client`
calls end up in phasync\hooked).

Run a private redis-server on 127.0.0.1:16399 (`redis-server --port 16399 --save '' --daemonize no`
in the background with a PID file); stop it by PID at the end.

## 3. The measurement

Three modes, one Swerve worker each (`--workers=1`), same app and routes:
- A: no extension, loader off (plain Swerve without ext-phasync);
- B: no extension, loader on (the plug);
- C: ext-phasync loaded (from phasync 2.0.4's tag: `git -C ~/dev/phasync show
  2.0.4:ext/phasync-8.5-nts-x86_64-glibc.so > <scratch>/phasync.so`, loaded via `PHP_INI_SCAN_DIR`
  with an ini file `extension=<path>`; see how swerve's tests do it), loader off.

For each mode and each I/O route: fire 10 requests concurrently and record the total wall time
(1 worker: ~10 s means requests ran one after another, ~1 s means they waited concurrently). Also
check every response body is identical across modes. For `/cpu`: per-request latency of 200
sequential requests in A and B, to show the loader's own overhead once everything is cached.

Expected if the plug works: B ≈ C ≈ 1 s on /sleep, /redis and /proc; A ≈ 10 s.

## Done when

Loader tests pass; the table of results (mode × route: total wall time for 10 concurrent requests,
identical responses yes/no) is in the README together with the opcache finding; anything that
didn't work, and why, is reported with evidence. No benchmarks beyond the measurements above;
stop every process you started (redis, swerve) by PID. Commit as you go (messages end with
`Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`).
