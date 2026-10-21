<?php

declare(strict_types=1);

require_once __DIR__ . '/nested/counter.php';
include_once __DIR__ . '/nested/counter.php'; // deduped: same real path already included
include __DIR__ . '/nested/counter.php'; // not _once: runs again

echo \json_encode([
    'file'  => __FILE__,
    'dir'   => __DIR__,
    'runs'  => $GLOBALS['counter_runs'], // 2: the _once pair deduped, plus one plain include
]), "\n";
