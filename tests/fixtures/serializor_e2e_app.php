<?php

declare(strict_types=1);

// The translated half of the Serializor end-to-end test's process B (see
// serializor_e2e_bootstrap.php, which requires this file through phasync\plug\path(): that is
// what makes this require of vendor/autoload.php itself get rewritten to call path() too,
// reaching Composer's ClassLoader and, through it, every class it goes on to autoload --
// Serializor's included, exactly as e2e/app/app.php reaches Predis the same way).
require \dirname(__DIR__, 2) . '/vendor/autoload.php';

// Serializor\ClosureStasis::getInstance() rebuilds the closure's code with
// require(ClosureStream::STREAM_PROTO . '://' . $code) -- the real trigger for
// Loader::resolve()'s scheme handling.
$serialized = \file_get_contents(\getenv('PLUG_SERIAL_FILE'));
$closure    = \Serializor\Serializor::unserialize($serialized);

echo \json_encode(['result' => $closure()]), "\n";
