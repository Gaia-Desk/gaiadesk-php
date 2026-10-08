<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use Psr\Http\Message\StreamInterface;

/**
 * One request as the transport sends it.
 *
 * @internal
 */
final class Call
{
    /**
     * @param array<string, string|int|null>                       $query
     * @param array{desk: string, op: string, request: array<string, mixed>}|null $e2e   a desk operation, sealed end to end when the hosted API can
     * @param int|null                                             $size  the size of $bytes, when it is a stream
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly mixed $json = null,
        public readonly StreamInterface|string|null $bytes = null,
        public readonly ?int $size = null,
        public readonly string $accept = 'application/json',
        public readonly ?string $deskToken = null,
        public readonly ?int $wake = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?array $e2e = null,
        public readonly ?float $timeout = null,
        public readonly bool $stream = false,
        public readonly bool $retry = true,
    ) {
    }

    /** `POST /desks/123456789/exec`. */
    public function op(): string
    {
        return $this->method.' '.$this->path;
    }
}
