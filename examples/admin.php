<?php

declare(strict_types=1);

// Run a command as administrator (root / SYSTEM). Needs an agent token minted with the
// `admin` scope AND the desk owner's Admin access switch, turned on at the desk.

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\Exception\RefusedException;
use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'), deskToken: env('GAIADESK_ADMIN_TOKEN'));

try {
    echo $gd->exec(env('DESK'), 'id', admin: true)['stdout'];
} catch (RefusedException $e) {
    // admin_scope_missing, admin_not_enabled, admin_denied (the person at the desk said no) or admin_unavailable
    fwrite(\STDERR, "refused ({$e->getReason()}): {$e->getMessage()}\n");
    exit(254);
}
