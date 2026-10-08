<?php

declare(strict_types=1);

// Run a command on a desk and print what it said.
//   GAIADESK_API_KEY=ak_… GAIADESK_DESK_TOKEN=gdagt_… DESK=123456789 php examples/quickstart.php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'), deskToken: env('GAIADESK_DESK_TOKEN'));
$desk = env('DESK');

try {
    $r = $gd->exec($desk, 'uname -a', timeout: '30s');
    echo $r['stdout'];
    fwrite(\STDERR, $r['stderr']);
    exit($r['exit']);
} catch (GaiaDeskException $e) {
    fwrite(\STDERR, sprintf("%s (%s, reason %s, request %s)\n", $e->getMessage(), $e->getKind(), $e->getReason() ?? '-', $e->getRequestId() ?? '-'));
    exit($e->getExitCode() ?? 255);
}
