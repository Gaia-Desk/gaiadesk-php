<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** A read-only body that gives out its pieces one per read, counting them; `$breakAfter` pieces, then it breaks off. */
final class ChunkedStream implements StreamInterface
{
    public int $reads = 0;
    public bool $closed = false;
    private string $pending = '';

    /** @param list<string> $chunks */
    public function __construct(private array $chunks, public ?int $breakAfter = null)
    {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->closed = true;

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->closed || ('' === $this->pending && [] === $this->chunks);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('no seek');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('no rewind');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('read-only');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('closed');
        }
        if ('' === $this->pending) {
            if (null !== $this->breakAfter && $this->reads >= $this->breakAfter) {
                throw new \RuntimeException('connection reset');
            }
            $this->pending = (string) array_shift($this->chunks);
            ++$this->reads;
        }
        $out = substr($this->pending, 0, $length);
        $this->pending = (string) substr($this->pending, \strlen($out));

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
