<?php

declare(strict_types=1);

/*
 * 'cache' mode's documented trade-off (see Loader's own docblock): Reflection's getFileName()
 * shows the cache *mirror* path, not the original -- unlike __FILE__/__DIR__, which fileConstants
 * keeps reporting the original. Symfony's compiled DI container locates a sibling file exactly
 * this way: dirname((new ReflectionClass($this))->getFileName()) . '/Sibling.php'. That computed
 * path is already "<cacheDir><original sibling path>" (dirname() of the mirror, plus the
 * sibling's own name, reconstructs the mirror's own path to it) -- so it must resolve to the
 * *same* mirror require_once already loaded from the original, never a nested mirror of it.
 */
require_once __DIR__ . '/reflect_sibling_target.php';

class ReflectSelfMarker
{
}

$reflectedFile        = (new \ReflectionClass(ReflectSelfMarker::class))->getFileName();
$siblingViaReflection = \dirname($reflectedFile) . '/reflect_sibling_target.php';
require_once $siblingViaReflection; // must be the exact same file as above: no redeclare

echo \json_encode([
    'file'          => __FILE__,
    'reflectedFile' => $reflectedFile,
    'interface'     => \interface_exists('ReflectSiblingInterface', false),
]), "\n";
