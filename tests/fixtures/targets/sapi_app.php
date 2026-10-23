<?php

/**
 * Translated by the loader with phasync-hooked's real map.php (not the tiny test-only one):
 * every call below reaches its phasync\hooked replacement. Runs inside
 * \phasync\hooked\sapi() (sapi_harness.php sets that up); its own echo, header(), ob_* and
 * session calls go to this request's own SapiTestDouble, not this process's real stdout.
 * session_start() runs first, before any output: like native PHP, the session cookie can't be
 * sent once output (and so the response head) has already left.
 */

session_save_path(\getenv('PLUG_SESSION_SAVE_PATH'));
session_id(\getenv('PLUG_SESSION_ID'));
session_start();
$_SESSION['hits'] = ($_SESSION['hits'] ?? 0) + 1;

header('X-From-App: 1');

ob_start();
echo 'buffered-';
$fromBuffer = ob_get_clean();

echo $fromBuffer;
echo 'echoed';

$body = file_get_contents('php://input');
echo " body={$body}";

echo ' session_id=' . session_id() . ' hits=' . $_SESSION['hits'];
session_write_close();
