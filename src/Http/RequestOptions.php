<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

/**
 * How one request is sent: its time limits and whether its body is read as it arrives.
 * The SDK's own clients ({@see CurlClient}, {@see SocketClient}) honour these; a plain
 * PSR-18 client applies its own configuration instead.
 */
final class RequestOptions
{
    /**
     * @param float|null $timeout         the most the whole request may take, in seconds (null: no limit)
     * @param float|null $idleTimeout     the longest the answer's body may go without a byte, in seconds (null: no limit);
     *                                    every read of it, never the body as a whole
     * @param float      $connectTimeout  the longest connecting may take, in seconds
     * @param bool       $stream          read the body as it arrives (event streams, downloads) instead of whole
     * @param float|null $responseTimeout the longest the answer may take to begin (its status and headers), sending
     *                                    the request included, in seconds (null: no limit)
     */
    public function __construct(
        public readonly ?float $timeout = null,
        public readonly ?float $idleTimeout = null,
        public readonly float $connectTimeout = 30.0,
        public readonly bool $stream = false,
        public readonly ?float $responseTimeout = null,
    ) {
    }
}
