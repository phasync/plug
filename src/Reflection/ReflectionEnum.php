<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionEnum; see {@see ReflectionClass}.
 *
 * @internal
 */
class ReflectionEnum extends \ReflectionEnum
{
    use UnmirrorsFileName;
    use WrapsClassAccessors;
}
