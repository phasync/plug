<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

use phasync\plug\Loader;

/**
 * The one override every class in this directory makes: `getFileName()` normally reports the
 * *mirror* path in 'cache' mode (the file PHP actually compiled), since, unlike `__FILE__`/
 * `__DIR__` (rewritten by phasync-babel's `fileConstants` option), nothing rewrites a Reflection
 * call. This maps it back to the original, the same path `fileConstants` already reports, so a
 * caller that locates itself through Reflection -- to find a sibling resource file, say -- gets
 * the real project tree (config/, templates/, every non-PHP file the mirror never has), not an
 * incomplete tree of translated .php files only.
 *
 * @internal
 */
trait UnmirrorsFileName
{
    public function getFileName(): string|false
    {
        $real = parent::getFileName();

        return false === $real ? false : Loader::unmirror($real);
    }
}
