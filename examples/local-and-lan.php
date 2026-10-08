<?php

declare(strict_types=1);

// On the desk itself (its own API), and from the same LAN (its gateway, pinned).

require __DIR__.'/../vendor/autoload.php';

use GaiaDesk\GaiaDesk;

$here = GaiaDesk::local();                 // ~/.gaiadesk/api.sock (or the Windows pipe) and its admin token
$self = $here->devices()['devices'][0]['desk_id'] ?? null;
if (null !== $self) {
    echo $here->stats($self)['hostname'], "\n";
}

$fp = getenv('GAIADESK_LAN_FINGERPRINT');
$token = getenv('GAIADESK_DESK_TOKEN');
if (false !== $fp && false !== $token && null !== $self) {
    $lan = GaiaDesk::lan("https://gaiadesk-$self.local:7443/v1", $fp, deskToken: $token);
    echo $lan->exec($self, 'hostname')['stdout'];
}
