<?php

declare(strict_types=1);

echo \json_encode([
    'file' => __FILE__,
    'dir'  => __DIR__,
    'upper' => \strtoupper('abc'), // mapped by harness.php: FixtureHooks\captured_strtoupper
    'sum'   => \array_sum([1, 2, 3]), // mapped: FixtureHooks\captured_array_sum
]), "\n";
