<?php

/**
 * Translated by the loader with phasync-hooked's real map.php (not the tiny test-only one):
 * every call below reaches its phasync\hooked replacement. Runs inside
 * \phasync\hooked\sapi() (sapi_harness.php sets that up); its own echo/header()/ob_* go to this
 * request's own SapiTestDouble, not this process's real stdout.
 */

header('X-From-App: 1');

ob_start();
echo 'buffered-';
$fromBuffer = ob_get_clean();

echo $fromBuffer;
echo 'echoed';

$body = file_get_contents('php://input');
echo " body={$body}";
