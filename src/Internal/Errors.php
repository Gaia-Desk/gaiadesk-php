<?php

declare(strict_types=1);

namespace GaiaDesk\Internal;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\OperationFailedException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;

/**
 * THE place that knows how the API spells an error: the envelope
 * `{"error": {"kind", "message", "reason"?, "desk"?, "request_id"?}}`, and which
 * exception class and SDK kind it maps to.
 *
 * @internal
 *
 * @phpstan-import-type ErrorDetails from GaiaDeskException
 *
 * @phpstan-type Envelope array{kind: string, message: string, reason?: string, desk?: string}
 */
final class Errors
{
    /**
     * What an answer says went wrong, or null when it is not an error envelope
     * (including exec's own `"error": null` on success).
     *
     * @return Envelope|null
     */
    public static function envelope(mixed $json): ?array
    {
        if (!\is_array($json) || !isset($json['error']) || !\is_array($json['error'])) {
            return null;
        }
        $e = $json['error'];
        if (!isset($e['kind']) || !\is_string($e['kind'])) {
            return null;
        }
        $env = ['kind' => $e['kind'], 'message' => isset($e['message']) && \is_string($e['message']) ? $e['message'] : ''];
        if (isset($e['reason']) && \is_string($e['reason']) && '' !== $e['reason']) {
            $env['reason'] = $e['reason'];
        }
        if (isset($e['desk']) && \is_string($e['desk']) && '' !== $e['desk']) {
            $env['desk'] = $e['desk'];
        }

        return $env;
    }

    /** The SDK kind for an error's kind and reason: the reason when it is an SDK kind, else the kind. */
    public static function sdkKind(string $kind, ?string $reason): string
    {
        if (null !== $reason && \in_array($reason, GaiaDeskException::SDK_KINDS, true)) {
            return $reason;
        }

        return $kind;
    }

    /**
     * The exception for an error's `kind` (one of the six). `details['kind']` (the SDK kind) defaults to `kind`.
     *
     * @param ErrorDetails $details
     */
    public static function forKind(string $kind, string $message, array $details): GaiaDeskException
    {
        $details['kind'] ??= $kind;

        return match ($kind) {
            'usage' => new UsageException($message, $details),
            'refused' => new RefusedException($message, $details),
            'connection_lost' => new ConnectionLostException($message, $details),
            'failed' => new OperationFailedException($message, $details),
            'protocol' => new ProtocolException($message, $details),
            'unreachable' => new UnreachableException($message, $details),
            default => new GaiaDeskException($message, $details),
        };
    }

    /**
     * The details an envelope adds: the SDK kind, reason and desk.
     *
     * @param Envelope     $env
     * @param ErrorDetails $details
     *
     * @return ErrorDetails
     */
    public static function envelopeDetails(array $env, array $details): array
    {
        $details['kind'] = self::sdkKind($env['kind'], $env['reason'] ?? null);
        if (isset($env['reason'])) {
            $details['reason'] = $env['reason'];
        }
        if (isset($env['desk'])) {
            $details['desk'] = $env['desk'];
        }

        return $details;
    }

    /** gaiadesk-cli's exit code for a desk operation that failed with this kind (1 failed, 254 refused, 130 interrupted, 255 the rest). */
    public static function deskOpExit(string $kind): int
    {
        return match ($kind) {
            'refused' => 254,
            'failed' => 1,
            'interrupted' => 130,
            default => 255,
        };
    }

    /**
     * An exec result's or stream event's `error`: null, or `{kind, message, reason?, desk?}`.
     *
     * @return array{kind: string, message: string, reason?: string, desk?: string}|null
     */
    public static function execError(mixed $error): ?array
    {
        if (!\is_array($error) || array_is_list($error) && [] !== $error) {
            return null;
        }
        if ([] === $error) {
            return null;
        }
        $out = [
            'kind' => isset($error['kind']) && \is_string($error['kind']) ? $error['kind'] : 'failed',
            'message' => isset($error['message']) && \is_string($error['message']) ? $error['message'] : '',
        ];
        if (isset($error['reason']) && \is_string($error['reason'])) {
            $out['reason'] = $error['reason'];
        }
        if (isset($error['desk']) && \is_string($error['desk'])) {
            $out['desk'] = $error['desk'];
        }

        return $out;
    }
}
