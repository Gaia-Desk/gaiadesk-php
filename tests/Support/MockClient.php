<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\TransportClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a FakeApi in this process. With `$streaming`, it is a
 * TransportClient whose bodies hand out the fake's pieces one read at a time (and count
 * how many were read), as a network would; else a plain PSR-18 client with whole bodies.
 * `$failNext` makes the next N requests fail with a NetworkException.
 */
final class MockClient implements TransportClient
{
    public int $failNext = 0;
    public bool $failConnect = false;
    /** @var list<RequestOptions> */
    public array $options = [];
    /** @var list<ChunkedStream> */
    public array $bodies = [];

    public function __construct(public FakeApi $api, private readonly bool $streaming = true)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->sendWith($request, new RequestOptions());
    }

    public function sendWith(RequestInterface $request, RequestOptions $options): ResponseInterface
    {
        $this->options[] = $options;
        if ($this->failNext > 0) {
            --$this->failNext;
            throw new NetworkException($request, 'connection reset by peer', false, $this->failConnect);
        }
        $headers = [];
        foreach ($request->getHeaders() as $k => $v) {
            $headers[strtolower((string) $k)] = implode(', ', $v);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $uri = $request->getUri();
        $target = $uri->getPath().('' !== $uri->getQuery() ? '?'.$uri->getQuery() : '');
        $r = $this->api->handle($request->getMethod(), $target, $headers, $body->getContents());
        $res = new Response($r->status, $r->headers);
        if ($this->streaming && $options->stream) {
            $s = new ChunkedStream($r->chunks);
            $this->bodies[] = $s;

            return $res->withBody($s);
        }

        return $res->withBody(Stream::create($r->body()));
    }
}
