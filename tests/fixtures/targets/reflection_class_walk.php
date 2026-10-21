<?php

declare(strict_types=1);

/*
 * cache mode: reproduces Symfony's Config\Resource\ReflectionClassResource::loadFiles(), which
 * recurses into getInterfaces()/getTraits()/getParentClass() with a \ReflectionClass-typed
 * parameter. Babel rewrites that type hint to phasync\plug\Reflection\ReflectionClass (mapped in
 * install()) -- but PHP's own getParentClass()/getInterfaces()/getTraits() always return plain,
 * native \ReflectionClass instances, never the calling subclass. Without WrapsClassAccessors
 * wrapping those results back into our subclass, the recursive call below throws a TypeError the
 * moment it reaches a parent, interface or trait.
 */

interface SomeInterface
{
}
trait SomeTrait
{
}
class SomeParent implements SomeInterface
{
    use SomeTrait;
}
class SomeChild extends SomeParent
{
}

/** @return list<string> */
function walk(\ReflectionClass $class): array
{
    $files = [];
    foreach ($class->getInterfaces() as $v) {
        $files = [...$files, ...walk($v)];
    }
    do {
        $files[] = $class->getFileName();
        foreach ($class->getTraits() as $v) {
            $files = [...$files, ...walk($v)];
        }
    } while ($class = $class->getParentClass());

    return $files;
}

echo \json_encode([
    'original' => __FILE__,
    'files' => \array_values(\array_unique(walk(new \ReflectionClass(SomeChild::class)))),
]), "\n";
