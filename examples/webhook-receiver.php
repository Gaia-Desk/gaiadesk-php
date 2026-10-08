<?php

declare(strict_types=1);

// A webhook endpoint (php -S 0.0.0.0:8080 examples/webhook-receiver.php): verify, de-duplicate, act.

require __DIR__.'/../vendor/autoload.php';

use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Webhooks;

$secret = (string) getenv('GAIADESK_WEBHOOK_SECRET'); // the whsec_… createWebhook() returned once
$raw = (string) file_get_contents('php://input');

try {
    $header = $_SERVER['HTTP_GAIADESK_SIGNATURE'] ?? '';
    $event = Webhooks::parse($secret, is_string($header) ? $header : '', $raw);
} catch (RefusedException) {
    http_response_code(400);
    exit;
}

// Delivery is at least once: de-duplicate by $event['id'] (stable across retries) in your store.
error_log('GaiaDesk event '.$event['type'].' '.$event['id']);
http_response_code(204);
