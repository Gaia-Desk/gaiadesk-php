<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The SDK's default HTTP client: PSR-18 over ext-curl, with what a plain PSR-18 client
 * cannot promise: per-request time limits, bodies read as they arrive (Server-Sent
 * Events stream live; downloads never sit in memory whole), uploads read from a stream
 * as they are sent, and HTTP over a Unix socket (the desk's local API).
 */
final class CurlClient implements TransportClient
{
    private readonly ResponseFactoryInterface $responses;
    private readonly StreamFactoryInterface $streams;

    /**
     * @param string|null       $unixSocket  send every request over this Unix socket (the URL then names the host only)
     * @param array<int, mixed> $curlOptions extra `CURLOPT_*` options for every request (a proxy, a CA bundle, ...)
     */
    public function __construct(
        private readonly ?string $unixSocket = null,
        private readonly array $curlOptions = [],
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $f = new Psr17Factory();
        $this->responses = $responseFactory ?? $f;
        $this->streams = $streamFactory ?? $f;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->sendWith($request, new RequestOptions());
    }

    public function sendWith(RequestInterface $request, RequestOptions $options): ResponseInterface
    {
        $t = new CurlTransfer($request, $options, $this->unixSocket, $this->curlOptions);
        try {
            $t->awaitHeaders();
            if ($options->stream) {
                $body = new CurlBodyStream($t);
            } else {
                $body = $this->streams->createStreamFromResource($t->awaitAll());
                $t->close();
            }
        } catch (\Throwable $e) {
            $t->close();
            throw $e;
        }
        $res = $this->responses->createResponse($t->status(), $t->reason())->withProtocolVersion($t->protocol());
        foreach ($t->headers() as [$name, $value]) {
            $res = $res->withAddedHeader($name, $value);
        }

        return $res->withBody($body);
    }
}
