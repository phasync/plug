<?php

declare(strict_types=1);

namespace phasync\plug\Tests;

/**
 * A minimal stream wrapper serving code from its own URL -- the same shape as
 * frodeborli/serializor's `Serializor\ClosureStream` (the real-world trigger for
 * {@see \phasync\plug\Loader::resolve()}'s scheme handling): `stream_open()` builds the
 * "file" it serves from nothing but the path PHP handed it, prefixed with `<?php\n`. Registered
 * for the 'plugtest' scheme, never 'file' -- it must stay a *second*, independent wrapper
 * alongside (or without) Loader's own, exactly as Serializor's wrapper exists alongside Loader's
 * in a real app.
 */
final class TestSchemeWrapper
{
    public const SCHEME = 'plugtest';

    private static bool $registered = false;

    public $context;

    private string $content;
    private int $length;
    private int $pos = 0;

    public static function register(): void
    {
        if (!self::$registered) {
            self::$registered = \stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->content = "<?php\n" . \substr($path, \strlen(self::SCHEME . '://'));
        $this->length  = \strlen($this->content);

        return true;
    }

    public function stream_read(int $count): string
    {
        $value = \substr($this->content, $this->pos, $count);
        $this->pos += \strlen($value);

        return $value;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= $this->length;
    }

    public function stream_stat(): array
    {
        return ['size' => $this->length];
    }

    public function url_stat(string $path, int $flags): array
    {
        $content = "<?php\n" . \substr($path, \strlen(self::SCHEME . '://'));

        return ['size' => \strlen($content)];
    }
}
