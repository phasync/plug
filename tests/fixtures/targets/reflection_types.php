<?php

declare(strict_types=1);

/*
 * cache mode: every one of the five Reflection replacements (install()'s class map) reports the
 * original path, same as __FILE__/__DIR__, not the cache mirror's -- including a
 * Symfony-Kernel::getProjectDir()-style resolution (dirname() of a reflected file), which is
 * exactly how a compiled DI container locates its own project root and, from there, non-PHP
 * resources (config/, templates/) the mirror never has.
 */

class SomeClass
{
}
enum SomeEnum
{
    case A;
}
function someFunction(): void
{
}
class MethodHost
{
    public function someMethod(): void
    {
    }
}

$class = new \ReflectionClass(SomeClass::class);
$object = new \ReflectionObject(new SomeClass());
$enum = new \ReflectionEnum(SomeEnum::class);
$function = new \ReflectionFunction('someFunction');
$method = new \ReflectionMethod(MethodHost::class, 'someMethod');

echo \json_encode([
    'original' => __FILE__,
    'class' => $class->getFileName(),
    'object' => $object->getFileName(),
    'enum' => $enum->getFileName(),
    'function' => $function->getFileName(),
    'method' => $method->getFileName(),
    'projectDir' => \dirname($object->getFileName()), // Symfony's own Kernel::getProjectDir() pattern
    'includedFiles' => \get_included_files(),
]), "\n";
