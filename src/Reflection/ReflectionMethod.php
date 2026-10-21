<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionMethod; see {@see ReflectionClass}.
 *
 * @internal
 */
class ReflectionMethod extends \ReflectionMethod
{
    use UnmirrorsFileName;
}
