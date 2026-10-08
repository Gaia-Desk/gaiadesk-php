<?php

declare(strict_types=1);

// Mint, list and revoke scoped agent tokens (the desk owner's own credential: a person's session token).

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$owner = new GaiaDesk(apiKey: env('GAIADESK_SESSION_TOKEN'));
$desk = env('DESK');

$minted = $owner->createToken($desk, 'ci-bot', expires: '30d', scopes: ['exec', 'cp', 'jobs'], cwd: '/srv/app');
echo 'secret (shown once): ', $minted['tokens'][0]['secret'], "\n";

foreach ($owner->listTokens($desk) as $t) {
    echo $t['id'], '  ', $t['label'], "\n";
}

$owner->revokeToken($desk, 'ci-bot');
