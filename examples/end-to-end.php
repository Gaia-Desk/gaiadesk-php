<?php

declare(strict_types=1);

// Require end-to-end encryption, and pin the desk's key (as its Settings show it).

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\Exception\E2eException;
use GaiaDesk\GaiaDesk;

$desk = env('DESK');
$gd = new GaiaDesk(
    apiKey: env('GAIADESK_API_KEY'),
    deskToken: env('GAIADESK_DESK_TOKEN'),
    e2e: 'require',                                     // never in the clear
    e2eKeys: false !== getenv('DESK_E2E_PUB') ? [$desk => (string) getenv('DESK_E2E_PUB')] : [],
);

try {
    echo $gd->exec($desk, 'cat /etc/hostname')['stdout'];
} catch (E2eException $e) {
    // e2e_unavailable (no key: an old or offline desk) or e2e_key_mismatch (not the pinned key). Nothing was sent.
    fwrite(\STDERR, "{$e->getReason()}: {$e->getMessage()}\n");
    exit(1);
}
