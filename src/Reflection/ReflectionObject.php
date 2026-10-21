<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionObject; see {@see ReflectionClass}.
 *
 * @internal
 */
class ReflectionObject extends \ReflectionObject
{
    use UnmirrorsFileName;
}
