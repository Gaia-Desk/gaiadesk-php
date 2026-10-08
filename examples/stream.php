<?php

declare(strict_types=1);

// Stream a command's output as it runs; Ctrl-C-safe cancel after 60 seconds.
//   GAIADESK_API_KEY=… GAIADESK_DESK_TOKEN=… DESK=… php examples/stream.php 'make test'

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_env.php';

use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(apiKey: env('GAIADESK_API_KEY'), deskToken: env('GAIADESK_DESK_TOKEN'));
$stream = $gd->execStream(env('DESK'), $argv[1] ?? 'for i in 1 2 3; do echo $i; sleep 1; done');
$started = time();
foreach ($stream->text() as $piece) {
    fwrite('stdout' === $piece['stream'] ? \STDOUT : \STDERR, $piece['text']);
    if (time() - $started > 60) {
        $stream->cancel(); // the server stops the command
    }
}
$exit = $stream->wait();
if (null !== $exit->error) {
    fwrite(\STDERR, "it did not finish: {$exit->error['message']}\n");
}
exit($exit->exitCode ?? 255);
