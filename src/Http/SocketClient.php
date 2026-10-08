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
 * connection (`Connection: close`), so nothing is ever re-sent on a reused one.
 *
 * Its time limits: the answer must begin within `responseTimeout`, writing the request
 * included (written without blocking, so a peer that stops reading it cannot hold it),
 * and every read of its body ends after `idleTimeout` without a byte. A Windows named
 * pipe opened as a file cannot be waited on with a limit: there, reads and writes block
 * (the pipe is the desk's own app, on the same machine).
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
        $now = microtime(true);
        $deadline = null !== $options->timeout && $options->timeout > 0 ? $now + $options->timeout : null;
        $idle = null !== $options->idleTimeout && $options->idleTimeout > 0 ? $options->idleTimeout : null;
        // The answer must begin by $headBy: the response timeout, or the whole request's limit when that is sooner.
        $headBy = $deadline;
        $headLimit = 'timeout';
        if (null !== $options->responseTimeout && $options->responseTimeout > 0 && (null === $headBy || $now + $options->responseTimeout < $headBy)) {
            $headBy = $now + $options->responseTimeout;
            $headLimit = 'responseTimeout';
        }
        try {
            $this->writeRequest($conn, $request, $headBy, $headLimit);
            [$status, $reason, $protocol, $headers, $rest] = $this->readHead($conn, $request, $headBy, $headLimit);
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
        $body = new SocketBodyStream($conn, $rest, $chunked, $length, $idle, $request);
        if (!$options->stream) {
            $tmp = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (false === $tmp) {
                throw new NetworkException($request, 'cannot open a temporary buffer for the answer');
            }
            try {
                while (!$body->eof()) {
                    if (null !== $deadline && microtime(true) > $deadline) {
                        throw new NetworkException($request, 'the request timed out', true, false, null, true, 'timeout');
                    }
                    fwrite($tmp, $body->read(65536));
                }
            } catch (\RuntimeException $e) {
                $body->close();
                fclose($tmp);
                throw $e instanceof NetworkException ? $e : new NetworkException($request, $e->getMessage(), false, false, $e, true);
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
    private function writeRequest($conn, RequestInterface $request, ?float $by, string $limit): void
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
        $write = static function (string $data) use ($conn, $request, $by, $limit): void {
            self::writeAll($conn, $data, $request, $by, $limit);
        };
        $write($head."\r\n");
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
            $write(null === $size ? dechex(\strlen($data))."\r\n".$data."\r\n" : $data);
        }
        if (null === $size) {
            $write("0\r\n\r\n");
        }
        @fflush($conn);
    }

    /**
     * Can this connection be waited on with a limit (a socket, not a named pipe opened as a file)?
     *
     * @param resource $conn
     */
    private static function selectable($conn): bool
    {
        return 'STDIO' !== stream_get_meta_data($conn)['stream_type'];
    }

    /**
     * @param resource $conn
     */
    private static function writeAll($conn, string $data, RequestInterface $request, ?float $by, string $limit): void
    {
        if (null === $by || !self::selectable($conn)) {
            while ('' !== $data) {
                $n = @fwrite($conn, $data);
                if (false === $n || 0 === $n) {
                    throw new \RuntimeException('the connection closed while the request was being written');
                }
                $data = substr($data, $n);
            }

            return;
        }
        // Without blocking, so a peer that stops reading cannot hold the request past its limit.
        stream_set_blocking($conn, false);
        try {
            while ('' !== $data) {
                $left = $by - microtime(true);
                if ($left <= 0) {
                    throw new NetworkException($request, 'no answer within the '.('timeout' === $limit ? 'time limit' : 'response timeout').' (the request was still being written)', true, false, null, false, $limit);
                }
                $r = $e = null;
                $w = [$conn];
                $wait = min($left, 1.0);
                $sec = (int) floor($wait);
                $ready = @stream_select($r, $w, $e, $sec, (int) (($wait - $sec) * 1e6));
                if (false === $ready) {
                    throw new \RuntimeException('the connection closed while the request was being written');
                }
                if (0 === $ready) {
                    continue;
                }
                $n = @fwrite($conn, $data);
                if (false === $n || (0 === $n && feof($conn))) {
                    throw new \RuntimeException('the connection closed while the request was being written');
                }
                if (0 === $n) {
                    usleep(1000); // TLS may want to read first: let it
                    continue;
                }
                $data = substr($data, $n);
            }
        } finally {
            if (\is_resource($conn)) {
                stream_set_blocking($conn, true);
            }
        }
    }

    /**
     * The status line and headers, and what was read past them.
     *
     * @param resource $conn
     *
     * @return array{int, string, string, list<array{string, string}>, string}
     */
    private function readHead($conn, RequestInterface $request, ?float $by, string $limit): array
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
            try {
                $left = null === $by ? null : $by - microtime(true);
                if (null !== $left && $left <= 0) {
                    throw new ReadTimedOut();
                }
                $data = self::readSome($conn, 8192, $left);
            } catch (ReadTimedOut) {
                throw new NetworkException($request, 'no answer within the '.('timeout' === $limit ? 'time limit' : 'response timeout'), true, false, null, false, $limit);
            }
            if (null === $data) {
                throw new NetworkException($request, '' === $buf ? 'the connection closed before an answer' : 'the connection closed in the middle of the answer\'s headers');
            }
            $buf .= $data;
        }
    }

    /**
     * Some bytes from a connection (blocking until there are some): null at its end.
     *
     * @param resource   $conn
     * @param float|null $timeout the longest to wait for a byte, in seconds (null: no limit)
     *
     * @throws ReadTimedOut when nothing arrives for $timeout seconds
     */
    public static function readSome($conn, int $length, ?float $timeout): ?string
    {
        $selectable = self::selectable($conn); // a named pipe opened as a file cannot time out
        $by = null !== $timeout && $timeout > 0 ? microtime(true) + $timeout : null;
        while (true) {
            if ($selectable) {
                // At most a second at a time (or what is left): with no limit, the socket's default timeout must not end the wait.
                $wait = null === $by ? 1.0 : max(0.001, min(1.0, $by - microtime(true)));
                $sec = (int) floor($wait);
                stream_set_timeout($conn, $sec, (int) (($wait - $sec) * 1e6));
            }
            $data = @fread($conn, max(1, $length));
            if (\is_string($data) && '' !== $data) {
                return $data;
            }
            if ($selectable && stream_get_meta_data($conn)['timed_out']) {
                if (null !== $by && microtime(true) >= $by) {
                    throw new ReadTimedOut('nothing arrived for '.$timeout.' s');
                }
                continue;
            }
            if (false === $data || feof($conn)) {
                return null;
            }
        }
    }
}
