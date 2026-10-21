<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionClass, mapped in {@see \phasync\plug\Loader::install()}:
 * a real \ReflectionClass (so `instanceof ReflectionClass` and every other check still holds for
 * untranslated code), with {@see getFileName()} reporting the original path, not the cache
 * mirror's, and {@see WrapsClassAccessors} keeping a walk up the class hierarchy in this same
 * subclass instead of handing a type-incompatible native instance to the next rewritten call.
 *
 * @internal
 */
class ReflectionClass extends \ReflectionClass
{
    use UnmirrorsFileName;
    use WrapsClassAccessors;
}
