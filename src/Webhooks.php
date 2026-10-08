<?php

declare(strict_types=1);

namespace GaiaDesk;

use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Internal\Json;

/**
 * Verifying webhook deliveries. Each delivery is a POST with
 * `GaiaDesk-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, keyed
 * with the subscription's secret (`whsec_…`). Verify every delivery over the RAW body,
 * reject a `t` more than five minutes off, and de-duplicate by the event id
 * (`GaiaDesk-Event-Id`): delivery is at least once.
 *
 * ```php
 * $event = GaiaDesk\Webhooks::parse($secret, $_SERVER['HTTP_GAIADESK_SIGNATURE'] ?? '', file_get_contents('php://input'));
 * ```
 *
 * @phpstan-import-type ApiWebhookEvent from Types
 */
final class Webhooks
{
    /** The most a delivery's `t` may be off, in seconds. */
    public const TOLERANCE = 300;

    /** Does the signature header prove this raw body came from GaiaDesk, signed with $secret, within $tolerance seconds of $now? */
    public static function verify(string $secret, string $signatureHeader, string $rawBody, ?int $now = null, int $tolerance = self::TOLERANCE): bool
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            $kv = explode('=', trim($p), 2);
            if (2 === \count($kv)) {
                $parts[$kv[0]] ??= $kv[1];
            }
        }
        $t = $parts['t'] ?? '';
        $v1 = strtolower($parts['v1'] ?? '');
        if (1 !== preg_match('/^\d{1,12}$/D', $t) || 1 !== preg_match('/^[0-9a-f]{64}$/D', $v1)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $t) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $t.'.'.$rawBody, $secret), $v1);
    }

    /**
     * The delivery's event, once its signature is verified; else a RefusedException
     * (`webhook_signature_invalid`).
     *
     * @return array{id: string, type: string, created?: int, data?: array<string, mixed>}
     */
    public static function parse(string $secret, string $signatureHeader, string $rawBody, ?int $now = null, int $tolerance = self::TOLERANCE): array
    {
        if (!self::verify($secret, $signatureHeader, $rawBody, $now, $tolerance)) {
            throw new RefusedException('the webhook delivery is not signed with this secret, or its timestamp is too far off', ['kind' => 'refused', 'reason' => 'webhook_signature_invalid']);
        }
        $event = Json::decode($rawBody);
        if (!\is_array($event) || !\is_string($event['id'] ?? null) || !\is_string($event['type'] ?? null)) {
            throw new RefusedException('the webhook delivery is not an event', ['kind' => 'refused', 'reason' => 'webhook_malformed']);
        }

        /** @var array{id: string, type: string, created?: int, data?: array<string, mixed>} $event */
        return $event;
    }

    /** A signature header for a body (for testing your own endpoint). */
    public static function sign(string $secret, string $rawBody, ?int $t = null): string
    {
        $t ??= time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$rawBody, $secret);
    }
}
