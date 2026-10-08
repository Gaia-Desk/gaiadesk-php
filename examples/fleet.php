<?php

declare(strict_types=1);

// List desks, wake an offline one, read the audit trail.

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'));

foreach ($gd->devices()['devices'] as $d) {
    echo $d['desk_id'], '  ', $d['name'] ?? '-', '  ', $d['online'] ? 'online' : 'offline: '.($d['offline_reason_text'] ?? '?'), "\n";
    if (!$d['online']) {
        $w = $gd->wake($d['desk_id'], waitS: 30);
        echo $w['woke'] ? "  woke\n" : "  rang, still asleep\n";
    }
}

foreach ($gd->iterateAuditEvents(action: 'api.*', max: 20) as $ev) {
    echo date('c', intdiv($ev['occurred_at_ms'], 1000)), '  ', $ev['action'], "\n";
}
