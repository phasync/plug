<?php

declare(strict_types=1);

$base = \sys_get_temp_dir() . '/plug-fileops-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
\mkdir($base);
\file_put_contents("$base/a.txt", 'hello');

$results = [];
$results['file_get_contents'] = \file_get_contents("$base/a.txt");

$h = \fopen("$base/a.txt", 'rb');
$results['fread'] = \fread($h, 100);
\fclose($h);

$results['is_file'] = \is_file("$base/a.txt");

$future = \time() + 100;
\touch("$base/a.txt", $future);
\clearstatcache();
$results['touch_mtime_matches'] = \filemtime("$base/a.txt") === $future;

\rename("$base/a.txt", "$base/b.txt");
$results['rename_old_gone']  = !\file_exists("$base/a.txt");
$results['rename_new_there'] = \file_exists("$base/b.txt");
$results['stat_size']        = \filesize("$base/b.txt");

$dh = \opendir($base);
$entries = [];
while (false !== ($e = \readdir($dh))) {
    if ('.' !== $e && '..' !== $e) {
        $entries[] = $e;
    }
}
\closedir($dh);
$results['opendir_entries'] = $entries;

\unlink("$base/b.txt");
$results['unlink_gone'] = !\file_exists("$base/b.txt");

\rmdir($base);
$results['rmdir_gone'] = !\is_dir($base);

echo \json_encode($results), "\n";
