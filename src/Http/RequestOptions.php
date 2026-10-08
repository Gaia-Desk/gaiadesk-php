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
     * @param float|null $timeout        the most the whole request may take, in seconds (null: no limit)
     * @param float|null $idleTimeout    the longest the answer may go without a byte, in seconds (null: no limit)
     * @param float      $connectTimeout the longest connecting may take, in seconds
     * @param bool       $stream         read the body as it arrives (event streams, downloads) instead of whole
     */
    public function __construct(
        public readonly ?float $timeout = null,
        public readonly ?float $idleTimeout = null,
        public readonly float $connectTimeout = 30.0,
        public readonly bool $stream = false,
    ) {
    }
}
