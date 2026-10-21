<?php

declare(strict_types=1);

/**
 * The actual application: required by swerve.php only after the loader (if this run uses it)
 * is installed, so every call below -- and everything required transitively, Predis included --
 * is compiled through phasync\plug\Loader when it is on.
 */

// One call, right here, so that if the loader is on, Predis's own files
// (fread/fwrite/fgets/stream_socket_client, ...) are translated the first time they are
// required -- at worker boot, not deferred to the first request. A TCP connection is not safe
// for concurrent use by more than one coroutine at a time (phasync's own rule: a second
// coroutine touching a busy connection fails loudly rather than silently corrupting it), so
// the /redis route below opens its own connection per request instead of sharing this one.
new Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 16399, 'read_write_timeout' => 5]);

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    $path = \strtok($request->getTarget(), '?');
    $body = match ($path) {
        '/hello' => 'ok',
        '/sleep' => (function () {
            \sleep(1);

            return 'slept';
        })(),
        '/redis' => (function () {
            // A connection of its own: BLPOP on a key nobody ever pushes to, a 1s blocking
            // server-side wait.
            $redis  = new Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 16399, 'read_write_timeout' => 5]);
            $result = $redis->blpop(['phasync-plug:e2e:empty-key'], 1);

            return null === $result ? 'redis-timeout' : 'redis-' . \implode(',', $result);
        })(),
        '/proc' => (function () {
            \exec('sleep 1; echo hi', $out);

            return \implode("\n", $out);
        })(),
        '/cpu' => (function () {
            $n = 0;
            for ($i = 0; $i < 200_000; ++$i) {
                $n += $i % 7;
            }

            return (string) $n;
        })(),
        default => null,
    };

    if (null === $body) {
        $request->sendResponseHeaders(404, ['content-type' => 'text/plain']);
        $request->write('not found');

        return;
    }

    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write($body);
});
