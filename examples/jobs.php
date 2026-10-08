<?php

declare(strict_types=1);

// Start a background job, follow its log, wait for it.

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'), deskToken: env('GAIADESK_DESK_TOKEN'));
$desk = env('DESK');

$job = $gd->runJob($desk, 'nightly', './build.sh --release', priority: 'low', cpu: 50, mem: '2G', keepAwake: true, shell: 'bash', env: ['CI' => '1'], idempotencyKey: 'nightly-'.date('Y-m-d'));
echo "started {$job['name']} ({$job['state']})\n";

foreach ($gd->followJobLogs($desk, 'nightly', tail: 4096) as $chunk) {
    echo $chunk->data;
}

$done = $gd->waitJob($desk, 'nightly', timeout: '2h');
echo $done['timed_out'] ? "still running\n" : 'exited '.($done['job']['exit_code'] ?? '?')."\n";

foreach ($gd->jobs($desk) as $j) {
    echo "{$j['name']}: {$j['state']}\n";
}
