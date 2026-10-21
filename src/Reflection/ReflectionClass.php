<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionClass, mapped in {@see \phasync\plug\Loader::install()}:
 * a real \ReflectionClass (so `instanceof ReflectionClass` and every other check still holds for
 * untranslated code), with {@see getFileName()} reporting the original path, not the cache
 * mirror's.
 *
 * @internal
 */
class ReflectionClass extends \ReflectionClass
{
    use UnmirrorsFileName;
}
