<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * 'cache' mode's replacement for \ReflectionFunction; see {@see ReflectionClass}.
 *
 * @internal
 */
class ReflectionFunction extends \ReflectionFunction
{
    use UnmirrorsFileName;
}
