<?php

declare(strict_types=1);

/**
 * Translated by the loader (harness.php maps the PHP_SAPI constant to phasync\hooked\php_sapi()):
 * reads PHP_SAPI outside any \Scope override, sets one on a fresh \Scope, reads it again while
 * that scope is current, and once more after returning -- exactly the mechanism e2e/app/swerve.php
 * wires up for real, end to end through the loader.
 */

$before = PHP_SAPI;
$scope  = new \Scope();
\phasync\hooked\set_sapi_name($scope, 'swerve-test');
$inside = $scope->run(static fn () => PHP_SAPI);
$after  = PHP_SAPI;

echo \json_encode([
    'before' => $before,
    'inside' => $inside,
    'after'  => $after,
    'real'   => \constant('PHP_SAPI'), // never rewritten (babel only maps a bare/qualified name)
]), "\n";
