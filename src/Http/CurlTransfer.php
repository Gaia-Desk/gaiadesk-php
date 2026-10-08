<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Message\RequestInterface;

/**
 * One request on a curl multi handle, driven by whoever needs its next bytes: the
 * client until the status and headers are in, then (streaming) the body as it is read.
 *
 * The time limits are kept here, in the loop that drives curl, so it is known which one
 * ran out: the answer must begin within `responseTimeout` (sending the request
 * included), and every wait for more of its body ends after `idleTimeout` without a
 * byte. A transfer that runs out of either is abandoned: its connection is closed.
 *
 * Each request has its own multi handle, so its own connection: libcurl re-sends a
 * request (any method, its body rewound) when a connection it REUSED closes before any
 * answer, and that never applies here. Anything but GET and HEAD also forces a fresh,
 * never-reused connection, even when the caller's options share a connection cache.
 *
 * @internal
 */
final class CurlTransfer
{
    /** Bodies up to this size are sent from memory; larger or unknown ones are read as curl sends them. */
    private const INLINE_BODY = 1048576;

    private \CurlHandle $ch;
    private \CurlMultiHandle $mh;
    private bool $done = false;
    private int $result = \CURLE_OK;
    private bool $closed = false;
    private bool $headersDone = false;
    private int $status = 0;
    private string $reason = '';
    private string $protocol = '1.1';
    /** @var list<array{string, string}> */
    private array $headers = [];
    private string $buffer = '';
    /** @var resource|null */
    private $sink;
    /** When the answer must have begun by (microtime), or null. */
    private ?float $responseBy = null;
    private ?float $idle = null;
    /** When the last byte of the body arrived (microtime). */
    private float $lastByte;

    /**
     * @param array<int, mixed> $curlOptions
     */
    public function __construct(
        private readonly RequestInterface $request,
        RequestOptions $options,
        ?string $unixSocket,
        array $curlOptions,
    ) {
        if (!$options->stream) {
            $sink = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (false === $sink) {
                throw new NetworkException($request, 'cannot open a temporary buffer for the answer');
            }
            $this->sink = $sink;
        }
        $ch = curl_init();
        $headers = ['Expect:'];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name.': '.implode(', ', $values);
        }
        $opts = [
            \CURLOPT_URL => (string) $request->getUri(),
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($options->connectTimeout * 1000),
            \CURLOPT_HEADERFUNCTION => $this->onHeader(...),
            \CURLOPT_WRITEFUNCTION => $this->onData(...),
        ];
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[\CURLOPT_PROTOCOLS_STR] = 'http,https';
        }
        if ('HEAD' === $request->getMethod()) {
            $opts[\CURLOPT_NOBODY] = true;
        }
        if (null !== $options->timeout && $options->timeout > 0) {
            $opts[\CURLOPT_TIMEOUT_MS] = (int) ceil($options->timeout * 1000);
        }
        // Not CURLOPT_LOW_SPEED_*: whole seconds, applied while the request is sent and while the
        // answer is awaited too, and it cannot say which limit ran out. The loop below keeps both.
        if (null !== $options->idleTimeout && $options->idleTimeout > 0) {
            $this->idle = $options->idleTimeout;
        }
        if (null !== $options->responseTimeout && $options->responseTimeout > 0) {
            $this->responseBy = microtime(true) + $options->responseTimeout;
        }
        $this->lastByte = microtime(true);
        if (null !== $unixSocket) {
            $opts[\CURLOPT_UNIX_SOCKET_PATH] = $unixSocket;
        }
        $body = $request->getBody();
        $size = $body->getSize();
        if (null !== $size && $size <= self::INLINE_BODY) {
            if ($size > 0) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                $opts[\CURLOPT_POSTFIELDS] = $body->getContents();
            }
        } else {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $opts[\CURLOPT_UPLOAD] = true;
            if (null !== $size) {
                $opts[\CURLOPT_INFILESIZE] = $size;
            }
            $opts[\CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($body): string {
                return $body->eof() ? '' : $body->read($length);
            };
        }
        foreach ($curlOptions as $k => $v) {
            $opts[$k] = $v;
        }
        $method = strtoupper($request->getMethod());
        if ('GET' !== $method && 'HEAD' !== $method) {
            // Never on a reused connection, never left for reuse: libcurl would re-send it, body and all,
            // if that connection turned out to be closed before any answer.
            $opts[\CURLOPT_FRESH_CONNECT] = true;
            $opts[\CURLOPT_FORBID_REUSE] = true;
        }
        if (!curl_setopt_array($ch, $opts)) {
            throw new NetworkException($request, 'curl refused the request options');
        }
        $this->ch = $ch;
        $this->mh = curl_multi_init();
        curl_multi_add_handle($this->mh, $this->ch);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function onHeader(\CurlHandle $ch, string $line): int
    {
        $len = \strlen($line);
        $t = rtrim($line, "\r\n");
        if (1 === preg_match('#^HTTP/(\S+)\s+(\d{3})\s*(.*)$#', $t, $m)) {
            // A new status line (after a 100 Continue, or a proxy's 200): start over.
            $this->protocol = $m[1];
            $this->status = (int) $m[2];
            $this->reason = $m[3];
            $this->headers = [];

            return $len;
        }
        if ('' === $t) {
            if ($this->status >= 200) {
                $this->headersDone = true;
            }

            return $len;
        }
        $i = strpos($t, ':');
        if (false !== $i) {
            $this->headers[] = [trim(substr($t, 0, $i)), trim(substr($t, $i + 1))];
        }

        return $len;
    }

    private function onData(\CurlHandle $ch, string $data): int
    {
        if ($this->closed) {
            return 0; // abort the transfer
        }
        $this->headersDone = true;
        $this->lastByte = microtime(true);
        if (null !== $this->sink) {
            fwrite($this->sink, $data);
        } else {
            $this->buffer .= $data;
        }

        return \strlen($data);
    }

    /** Let curl do what it can now, waiting up to $wait seconds for the network. */
    private function drive(float $wait): void
    {
        if ($this->done || $this->closed) {
            return;
        }
        do {
            $mrc = curl_multi_exec($this->mh, $running);
        } while (\CURLM_CALL_MULTI_PERFORM === $mrc);
        while (false !== ($info = curl_multi_info_read($this->mh))) {
            if ($info['handle'] === $this->ch) {
                $this->done = true;
                $this->result = \is_int($info['result']) ? $info['result'] : \CURLE_RECV_ERROR;
            }
        }
        if (!$this->done && $running > 0 && $wait > 0) {
            if (-1 === curl_multi_select($this->mh, $wait)) {
                usleep(1000);
            }
        }
    }

    /** Drive until the status and headers are in (or it failed). */
    public function awaitHeaders(): void
    {
        while (!$this->headersDone && !$this->done) {
            $this->drive($this->untilResponseLimit());
        }
        if (!$this->headersDone || $this->status < 100) {
            throw $this->failure();
        }
        $this->lastByte = microtime(true);
    }

    /** How long to wait for the network now, before the answer began: throws once responseTimeout ran out. */
    private function untilResponseLimit(): float
    {
        if (null === $this->responseBy) {
            return 1.0;
        }
        $left = $this->responseBy - microtime(true);
        if ($left <= 0) {
            $this->close();
            throw new NetworkException($this->request, 'no answer within the response timeout', true, false, null, false, 'responseTimeout');
        }

        return min(1.0, max(0.001, $left));
    }

    /** How long to wait for the network now, within the body: throws once idleTimeout passed without a byte. */
    private function untilIdleLimit(): float
    {
        if (null === $this->idle) {
            return 1.0;
        }
        $left = $this->lastByte + $this->idle - microtime(true);
        if ($left <= 0) {
            $this->close();
            throw new NetworkException($this->request, 'nothing arrived within the idle timeout', true, false, null, true, 'idleTimeout');
        }

        return min(1.0, max(0.001, $left));
    }

    /**
     * Drive until the whole answer is in the sink; the sink, rewound.
     *
     * @return resource
     */
    public function awaitAll()
    {
        while (!$this->done) {
            $this->drive($this->untilIdleLimit());
        }
        if (\CURLE_OK !== $this->result || null === $this->sink) {
            throw $this->failure();
        }
        rewind($this->sink);
        $sink = $this->sink;
        $this->sink = null;

        return $sink;
    }

    /**
     * Up to $length bytes of the body, waiting for them: '' at its end.
     *
     * @throws NetworkException when the answer broke off
     */
    public function read(int $length): string
    {
        while ('' === $this->buffer && !$this->done && !$this->closed) {
            $this->drive($this->untilIdleLimit());
        }
        if ('' === $this->buffer) {
            if ($this->done && \CURLE_OK !== $this->result) {
                throw $this->failure();
            }

            return '';
        }
        $out = substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, $length);

        return $out;
    }

    public function eof(): bool
    {
        // A transfer that failed is not at its end: the next read says why.
        return '' === $this->buffer && ($this->closed || ($this->done && \CURLE_OK === $this->result));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        curl_multi_remove_handle($this->mh, $this->ch);
        // Since PHP 8, curl_multi_close() and curl_close() do nothing: the handles (and the connection)
        // go when the objects do. The callbacks bound to $this make a cycle that only the garbage
        // collector would break, much later, so drop them now: the connection closes here.
        unset($this->ch, $this->mh);
        if (null !== $this->sink) {
            fclose($this->sink);
            $this->sink = null;
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    /** @return list<array{string, string}> */
    public function headers(): array
    {
        return $this->headers;
    }

    private function failure(): NetworkException
    {
        $code = $this->result;
        $msg = curl_strerror($code) ?? 'unknown error';
        $detail = $this->closed ? '' : curl_error($this->ch);
        $text = '' !== $detail ? $detail : $msg;
        $connect = \in_array($code, [\CURLE_COULDNT_CONNECT, \CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_RESOLVE_PROXY], true);
        if (\CURLE_OK === $code) {
            $text = 'the connection closed before an answer';
        }

        $timedOut = \CURLE_OPERATION_TIMEDOUT === $code;

        return new NetworkException($this->request, $text, $timedOut, $connect, null, $this->headersDone, $timedOut ? 'timeout' : null);
    }
}
