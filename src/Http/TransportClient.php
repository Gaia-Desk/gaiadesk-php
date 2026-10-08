<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that also takes per-request time limits and can hand back a body
 * that is read as it arrives (Server-Sent Events, downloads). The SDK's
 * {@see CurlClient} (the default) and {@see SocketClient} (the Windows named pipe and the
 * LAN gateway's pinned TLS) implement it.
 */
interface TransportClient extends ClientInterface
{
    /**
     * Send a request. With `$options->stream`, return once the status and headers are in,
     * with a body that reads the rest as it arrives; else with the whole body.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface when there is no answer
     */
    public function sendWith(RequestInterface $request, RequestOptions $options): ResponseInterface;
}
