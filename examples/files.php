<?php

declare(strict_types=1);

// Copy files both ways (at most 256 MB each through the API; streamed, never whole in memory).

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'), deskToken: env('GAIADESK_DESK_TOKEN'));
$desk = env('DESK');

$gd->uploadFrom("hello from PHP\n", $desk, 'inbox/hello.txt');            // bytes
$up = $gd->upload(__FILE__, $desk, 'inbox/');                               // a local file, keeping its name
echo "uploaded {$up['bytes']} bytes to {$up['destination']}\n";

$down = $gd->download($desk, 'inbox/hello.txt', sys_get_temp_dir().'/');   // into a folder
echo "downloaded {$down['bytes']} bytes to {$down['destination']}\n";

$out = fopen('php://stdout', 'wb');
if (false !== $out) {
    $gd->downloadTo($desk, 'inbox/hello.txt', $out);                       // into any stream
}
