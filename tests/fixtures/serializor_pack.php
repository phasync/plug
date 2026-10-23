<?php

declare(strict_types=1);

/**
 * Process A of the Serializor end-to-end test: serializes a static closure -- natively, no
 * Loader involved -- and writes the result to the file named by argv[1]. Run as its own
 * process (tests/LoaderTest.php) so "one process serializes, another unserializes" is not just
 * simulated within a single run.
 *
 * Usage: php serializor_pack.php <outFile>
 */

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $outFile] = $argv;

// strtoupper() here is mapped by harness.php's map: when the unserializing side (running the
// Loader) rebuilds this closure's code through its own stream wrapper, this call proves that
// code was translated too, not just the file that requested it.
$closure = static function (): string {
    return \strtoupper('closure-ran');
};

\file_put_contents($outFile, \Serializor\Serializor::serialize($closure));
