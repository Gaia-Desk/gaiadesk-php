<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Message\StreamInterface;

/**
 * An HTTP/1.1 response body read from its connection as it arrives: by
 * `Content-Length`, `Transfer-Encoding: chunked`, or until the connection closes.
 * Closing it closes the connection.
 *
 * @internal
 */
final class SocketBodyStream implements StreamInterface
{
    private int $position = 0;
    /** Bytes left in this chunk (chunked) or in the body (Content-Length); null: until the connection closes. */
    private ?int $left;
    private bool $ended = false;

    /**
     * @param resource|null $conn
     */
    public function __construct(private $conn, private string $pending, private readonly bool $chunked, ?int $length, private readonly ?float $idleTimeout)
    {
        $this->left = $chunked ? 0 : $length;
        if (!$chunked && 0 === $length) {
            $this->ended = true;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (\is_resource($this->conn)) {
            fclose($this->conn);
        }
        $this->conn = null;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->ended || null === $this->conn && '' === $this->pending;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('a streamed answer cannot seek');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('a streamed answer cannot rewind');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('a response body is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /** More bytes from the connection into $pending: false at its end. */
    private function fill(): bool
    {
        if (!\is_resource($this->conn)) {
            return false;
        }
        $data = SocketClient::readSome($this->conn, 65536, $this->idleTimeout);
        if (null === $data) {
            return false;
        }
        $this->pending .= $data;

        return true;
    }

    /** One CRLF-terminated line from the connection (without the CRLF). */
    private function line(): string
    {
        while (false === ($i = strpos($this->pending, "\n"))) {
            if (!$this->fill()) {
                throw new \RuntimeException('the answer broke off (a chunked body ended early)');
            }
        }
        $line = rtrim(substr($this->pending, 0, $i), "\r");
        $this->pending = (string) substr($this->pending, $i + 1);

        return $line;
    }

    public function read(int $length): string
    {
        if ($this->ended) {
            return '';
        }
        $length = max(1, $length);
        if ($this->chunked && 0 === $this->left) {
            $size = hexdec(trim(explode(';', $this->line(), 2)[0]));
            if (!\is_int($size) || $size < 0) {
                throw new \RuntimeException('the answer is not valid chunked encoding');
            }
            if (0 === $size) {
                // Trailers, then the blank line.
                while ('' !== $this->line()) {
                }
                $this->ended = true;
                $this->close();

                return '';
            }
            $this->left = $size;
        }
        if ('' === $this->pending && !$this->fill()) {
            if (null === $this->left) {
                $this->ended = true;
                $this->close();

                return '';
            }
            throw new \RuntimeException('the answer broke off before its end');
        }
        $n = null === $this->left ? $length : min($length, $this->left);
        $out = substr($this->pending, 0, $n);
        $this->pending = (string) substr($this->pending, \strlen($out));
        $this->position += \strlen($out);
        if (null !== $this->left) {
            $this->left -= \strlen($out);
            if ($this->chunked && 0 === $this->left) {
                $this->line(); // the CRLF after the chunk
            } elseif (!$this->chunked && 0 === $this->left) {
                $this->ended = true;
                $this->close();
            }
        }

        return $out;
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(65536);
        }

        return $out;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
