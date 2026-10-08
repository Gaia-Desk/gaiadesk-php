<?php

declare(strict_types=1);

// Your backend creates a support session; your page gets the embed token.

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY')); // a key with the `support` scope

$s = $gd->createSupportSession(mode: 'cobrowse', customer: ['name' => 'Ada', 'plan' => 'pro'], expiresIn: 1800, origin: 'https://app.example.com');
echo json_encode(['embedToken' => $s['embed_token']]), "\n"; // hand to GaiaDeskEmbed.start({ embedToken })
echo "agents join with {$s['join_code']} at {$s['join_url']}\n";

foreach ($gd->supportSessions() as $open) {
    echo "{$open['id']}  {$open['state']}  ".($open['customer_present'] ? 'customer here' : 'waiting for the customer')."\n";
}
