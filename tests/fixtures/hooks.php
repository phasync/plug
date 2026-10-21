<?php

declare(strict_types=1);

/**
 * Test-only replacements, mapped by tests/fixtures/harness.php. Each writes a one-word sentinel
 * to the file named by the PLUG_MARKER environment variable, then calls the real native
 * function so its own result is unaffected: a test fixture can check both that the hooked
 * replacement actually ran (the marker file) and that the visible behaviour didn't change.
 */

namespace FixtureHooks;

function captured_strtoupper(string $string): string
{
    $marker = \getenv('PLUG_MARKER');
    if (false !== $marker) {
        \file_put_contents($marker, 'HOOKED-strtoupper', \FILE_APPEND);
    }

    return \strtoupper($string);
}

function captured_array_sum(array $array): int|float
{
    $marker = \getenv('PLUG_MARKER');
    if (false !== $marker) {
        \file_put_contents($marker, 'HOOKED-array_sum', \FILE_APPEND);
    }

    return \array_sum($array);
}
