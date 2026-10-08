<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A response body that is read from the network as it arrives (read-only, not
 * seekable). Closing it ends the request: the server sees the caller hang up.
 *
 * @internal
 */
final class CurlBodyStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private ?CurlTransfer $transfer)
    {
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
        $this->transfer?->close();
        $this->transfer = null;
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
        return null === $this->transfer || $this->transfer->eof();
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
        return null !== $this->transfer;
    }

    public function read(int $length): string
    {
        if (null === $this->transfer) {
            throw new \RuntimeException('the stream is closed');
        }
        try {
            $data = $this->transfer->read(max(1, $length));
        } catch (NetworkException $e) {
            // A NetworkException (a \RuntimeException) that says the answer had begun, and why it ended.
            throw new NetworkException($e->getRequest(), 'the answer broke off: '.$e->getMessage(), $e->timedOut, false, $e, true, $e->limit);
        }
        $this->position += \strlen($data);

        return $data;
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
