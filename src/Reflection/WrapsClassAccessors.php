<?php

declare(strict_types=1);

namespace phasync\plug\Reflection;

/**
 * `ReflectionClass::getParentClass()`, `getInterfaces()` and `getTraits()` are native PHP
 * methods that always return plain `\ReflectionClass` instances -- never the calling object's
 * own class -- regardless of any subclass the reflected object was created through. Left alone,
 * a caller that walks a class hierarchy this way (Symfony's `ReflectionClassResource::loadFiles()`
 * does exactly this, recursing into every interface, trait and parent) eventually hands one of
 * these native instances to a function whose parameter type babel rewrote to expect
 * {@see ReflectionClass} specifically, which throws a `TypeError`.
 *
 * Wrapping every result as our own {@see ReflectionClass} closes that hole for these three
 * methods. It is not exhaustive: other native APIs that return a bare `\ReflectionClass`
 * (`ReflectionProperty::getDeclaringClass()`, `ReflectionClassConstant::getDeclaringClass()`,
 * `ReflectionFunctionAbstract::getClosureScopeClass()`, ...) are not wrapped and can still throw
 * the same `TypeError` if application code receives one where babel expects our subclass -- same
 * residual limitation as {@see UnmirrorsFileName}'s note on `Exception::getFile()`. Wrapping those
 * too would mean re-wrapping most of the Reflection API's surface for a problem not yet observed
 * in practice; see PLUG-GAPS.md.
 *
 * @internal
 */
trait WrapsClassAccessors
{
    public function getParentClass(): \ReflectionClass|false
    {
        $parent = parent::getParentClass();

        return false === $parent ? false : new ReflectionClass($parent->getName());
    }

    /** @return array<string, \ReflectionClass> */
    public function getInterfaces(): array
    {
        $out = [];
        foreach (parent::getInterfaces() as $name => $interface) {
            $out[$name] = new ReflectionClass($interface->getName());
        }

        return $out;
    }

    /** @return array<string, \ReflectionClass> */
    public function getTraits(): array
    {
        $out = [];
        foreach (parent::getTraits() as $name => $trait) {
            $out[$name] = new ReflectionClass($trait->getName());
        }

        return $out;
    }
}
