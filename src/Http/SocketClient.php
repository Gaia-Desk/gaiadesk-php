<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * HTTP/1.1 written on a PHP stream that something else opened and verified: the desk's
 * Windows named pipe (`\\.\pipe\gaiadesk-api-<user>`, which curl cannot open), a Unix
 * socket, or the LAN gateway's TLS connection after its certificate was checked against
 * the pinned fingerprint and before any byte of the request is written. One request per
 * connection (`Connection: close`).
 */
final class SocketClient implements TransportClient
{
    /** @var \Closure(RequestInterface, RequestOptions): resource */
    private readonly \Closure $connect;
    private readonly ResponseFactoryInterface $responses;
    private readonly StreamFactoryInterface $streams;

    /**
     * @param callable(RequestInterface, RequestOptions): resource $connect opens (and verifies) the connection a request is written on;
     *                                                                       throws a NetworkException (or the SDK's own exception) when it cannot
     * @param string|null                                          $host    the Host header (default: the URL's host)
     */
    public function __construct(callable $connect, private readonly ?string $host = null, ?ResponseFactoryInterface $responseFactory = null, ?StreamFactoryInterface $streamFactory = null)
    {
        $this->connect = $connect(...);
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
        $conn = ($this->connect)($request, $options);
        $deadline = null !== $options->timeout && $options->timeout > 0 ? microtime(true) + $options->timeout : null;
        $idle = $options->idleTimeout;
        try {
            $this->writeRequest($conn, $request);
            [$status, $reason, $protocol, $headers, $rest] = $this->readHead($conn, $request, $idle, $deadline);
        } catch (\Throwable $e) {
            if (\is_resource($conn)) {
                fclose($conn);
            }
            throw $e instanceof NetworkException ? $e : new NetworkException($request, $e->getMessage(), false, false, $e);
        }
        $chunked = false;
        $length = null;
        foreach ($headers as [$name, $value]) {
            $n = strtolower($name);
            if ('transfer-encoding' === $n && str_contains(strtolower($value), 'chunked')) {
                $chunked = true;
            } elseif ('content-length' === $n && 1 === preg_match('/^\d+$/D', trim($value))) {
                $length = (int) trim($value);
            }
        }
        if ('HEAD' === $request->getMethod() || 204 === $status || 304 === $status) {
            $chunked = false;
            $length = 0;
        }
        $body = new SocketBodyStream($conn, $rest, $chunked, $length, $idle);
        if (!$options->stream) {
            $tmp = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (false === $tmp) {
                throw new NetworkException($request, 'cannot open a temporary buffer for the answer');
            }
            try {
                while (!$body->eof()) {
                    if (null !== $deadline && microtime(true) > $deadline) {
                        throw new NetworkException($request, 'the request timed out', true);
                    }
                    fwrite($tmp, $body->read(65536));
                }
            } catch (\RuntimeException $e) {
                $body->close();
                fclose($tmp);
                throw $e instanceof NetworkException ? $e : new NetworkException($request, $e->getMessage(), false, false, $e);
            }
            rewind($tmp);
            $body = $this->streams->createStreamFromResource($tmp);
        }
        $res = $this->responses->createResponse($status, $reason)->withProtocolVersion($protocol);
        foreach ($headers as [$name, $value]) {
            $res = $res->withAddedHeader($name, $value);
        }

        return $res->withBody($body);
    }

    /**
     * @param resource $conn
     */
    private function writeRequest($conn, RequestInterface $request): void
    {
        $uri = $request->getUri();
        $target = $request->getRequestTarget();
        $body = $request->getBody();
        $size = $body->getSize();
        $head = $request->getMethod().' '.$target." HTTP/1.1\r\n";
        $host = $this->host ?? ($uri->getHost().(null !== $uri->getPort() ? ':'.$uri->getPort() : ''));
        $head .= 'Host: '.$host."\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            $l = strtolower((string) $name);
            if ('host' === $l || 'content-length' === $l || 'transfer-encoding' === $l || 'connection' === $l) {
                continue;
            }
            $head .= $name.': '.implode(', ', $values)."\r\n";
        }
        $head .= "Connection: close\r\n";
        $hasBody = 0 !== $size;
        if (null !== $size && $size > 0) {
            $head .= 'Content-Length: '.$size."\r\n";
        } elseif (null === $size) {
            $head .= "Transfer-Encoding: chunked\r\n";
        }
        self::writeAll($conn, $head."\r\n");
        if (!$hasBody) {
            return;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $data = $body->read(65536);
            if ('' === $data) {
                continue;
            }
            self::writeAll($conn, null === $size ? dechex(\strlen($data))."\r\n".$data."\r\n" : $data);
        }
        if (null === $size) {
            self::writeAll($conn, "0\r\n\r\n");
        }
        fflush($conn);
    }

    /**
     * @param resource $conn
     */
    private static function writeAll($conn, string $data): void
    {
        while ('' !== $data) {
            $n = @fwrite($conn, $data);
            if (false === $n || 0 === $n) {
                throw new \RuntimeException('the connection closed while the request was being written');
            }
            $data = substr($data, $n);
        }
    }

    /**
     * The status line and headers, and what was read past them.
     *
     * @param resource $conn
     *
     * @return array{int, string, string, list<array{string, string}>, string}
     */
    private function readHead($conn, RequestInterface $request, ?float $idle, ?float $deadline): array
    {
        $buf = '';
        while (true) {
            $end = strpos($buf, "\r\n\r\n");
            if (false !== $end) {
                $head = substr($buf, 0, $end);
                $rest = (string) substr($buf, $end + 4);
                $lines = explode("\r\n", $head);
                $statusLine = array_shift($lines);
                if (1 !== preg_match('#^HTTP/(\d(?:\.\d)?)\s+(\d{3})\s*(.*)$#', $statusLine, $m)) {
                    throw new NetworkException($request, 'the answer is not HTTP');
                }
                $status = (int) $m[2];
                if ($status >= 100 && $status < 200) {
                    $buf = $rest; // an interim answer: the real one follows

                    continue;
                }
                $headers = [];
                foreach ($lines as $l) {
                    $i = strpos($l, ':');
                    if (false !== $i) {
                        $headers[] = [trim(substr($l, 0, $i)), trim(substr($l, $i + 1))];
                    }
                }

                return [$status, $m[3], $m[1], $headers, $rest];
            }
            if (\strlen($buf) > 65536) {
                throw new NetworkException($request, 'the answer\'s headers are too long');
            }
            $wait = $idle;
            if (null !== $deadline) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new NetworkException($request, 'the request timed out', true);
                }
                $wait = null === $wait ? $left : min($wait, $left);
            }
            $data = self::readSome($conn, 8192, $wait);
            if (null === $data) {
                throw new NetworkException($request, '' === $buf ? 'the connection closed before an answer' : 'the connection closed in the middle of the answer\'s headers');
            }
            $buf .= $data;
        }
    }

    /**
     * Some bytes from a connection (blocking until there are some): null at its end.
     *
     * @param resource $conn
     *
     * @throws NetworkException|\RuntimeException when nothing arrives for $timeout seconds
     */
    public static function readSome($conn, int $length, ?float $timeout): ?string
    {
        $meta = stream_get_meta_data($conn);
        $selectable = 'STDIO' !== $meta['stream_type']; // a named pipe opened as a file cannot time out
        if (null !== $timeout && $timeout > 0 && $selectable) {
            $sec = (int) floor($timeout);
            stream_set_timeout($conn, $sec, (int) (($timeout - $sec) * 1e6));
        }
        while (true) {
            $data = @fread($conn, max(1, $length));
            if (false === $data) {
                return null;
            }
            if ('' !== $data) {
                return $data;
            }
            if (stream_get_meta_data($conn)['timed_out']) {
                throw new \RuntimeException('nothing arrived for '.$timeout.' seconds');
            }
            if (feof($conn)) {
                return null;
            }
        }
    }
}
