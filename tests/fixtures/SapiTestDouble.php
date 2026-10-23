<?php

declare(strict_types=1);

/**
 * The `$sapi` object for the `sapi()` end-to-end test ({@see SapiTest}): a minimal double, just
 * enough of the `phasync\hooked\sapi()`/`phasync\ext\sapi()` contract for `sapi_harness.php`'s
 * scenario (`echo`, `header()`, `ob_start()`/`ob_get_clean()`, `php://input`) -- phasync-hooked's
 * own, fuller `tests/SapiDouble.php` is this package's test-only inspiration, not reused directly,
 * since this proof is meant to stand on its own.
 *
 * @internal
 */
final class SapiTestDouble
{
    /** @var resource the request's end, handed back by output() */
    public $outputRequestEnd;

    /** @var resource this end: read with drain() */
    public $outputServerEnd;

    /** @var resource|null the request's end of the body pair, handed back by input() */
    public $inputRequestEnd;

    /** @var list<array{int, ?string, list<string>}> every send_headers() call, in order */
    public array $headersSent = [];

    public function __construct(?string $body = null)
    {
        [$this->outputRequestEnd, $this->outputServerEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \stream_set_blocking($this->outputServerEnd, false);
        if (null !== $body) {
            [$this->inputRequestEnd, $bodyServerEnd] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \fwrite($bodyServerEnd, $body);
            \stream_socket_shutdown($bodyServerEnd, \STREAM_SHUT_WR);
            \fclose($bodyServerEnd);
        }
    }

    public function name(): string
    {
        return 'plug-e2e';
    }

    public function output()
    {
        return $this->outputRequestEnd;
    }

    public function input()
    {
        return $this->inputRequestEnd;
    }

    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
        $this->headersSent[] = [$status, $statusLine, $headers];
    }

    public function request_info(): array
    {
        return ['method' => 'GET', 'content_type' => null, 'content_length' => null, 'query_string' => '', 'request_uri' => '/'];
    }

    /** Everything written to output() so far, drained non-blockingly. */
    public function drain(): string
    {
        $data = '';
        while ('' !== ($chunk = @\fread($this->outputServerEnd, 65536)) && false !== $chunk) {
            $data .= $chunk;
        }

        return $data;
    }
}
