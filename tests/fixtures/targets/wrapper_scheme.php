<?php

declare(strict_types=1);

// Mirrors the real trigger (Serializor\ClosureStream): code is included through a stream
// wrapper whose URL *is* the code, registered under a scheme other than 'file'.
require __DIR__ . '/../TestSchemeWrapper.php'; // not phasync\plug\Loader's own wrapper: 'file' is untouched
\phasync\plug\Tests\TestSchemeWrapper::register();

$code = 'return ["upper" => \strtoupper("ab"), "file" => __FILE__, "dir" => __DIR__];';
$url  = \phasync\plug\Tests\TestSchemeWrapper::SCHEME . '://' . $code;

// What translated code calling require($url) directly (as Serializor's own unserializer does)
// is rewritten to call in 'cache' mode, and what this package's own entry points call
// explicitly in either mode (see Loader::resolve()'s docblock).
$resolved = \phasync\plug\path($url, __DIR__);
$data     = require $resolved;

$data['url'] = $url;

echo \json_encode($data), "\n";
