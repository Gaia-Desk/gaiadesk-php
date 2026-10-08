<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\ReadTimedOut;
use GaiaDesk\Http\SocketClient;
use Psr\Http\Message\StreamInterface;

/**
 * An answer's body as the SDK reads it: an answer that stalls or breaks off is a
 * {@see ConnectionLostException} (kind `timeout` when nothing arrived for `idleTimeout`
 * seconds, else `connection_lost`, reason `incomplete`), never a raw stream error.
 *
 * The SDK's own clients bound every read themselves. A plain PSR-18 client's body that is
 * a PHP stream (Guzzle's streamed bodies, nyholm/psr7's) is taken over, and each read of
 * it waits at most `idleTimeout`; any other body is read as the client gives it.
 *
 * @internal
 */
final class AnswerBody implements StreamInterface
{
    /** @var resource|null the PHP stream taken over from a plain PSR-18 client's body */
    private $raw;
    private bool $rawEnded = false;
    private int $position = 0;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly string $where,
        private readonly string $op,
        private readonly ?float $idleTimeout,
        bool $bounded,
    ) {
        if (!$bounded && null !== $idleTimeout && \is_string($inner->getMetadata('stream_type'))) {
            $raw = $inner->detach();
            if (\is_resource($raw)) {
                $this->raw = $raw;
            }
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

    /** The ConnectionLostException for an answer that stalled (nothing for idleTimeout seconds). */
    private function stalled(\Throwable $e): ConnectionLostException
    {
        $this->close();
        $limit = $e instanceof NetworkException && 'timeout' === $e->limit ? 'its time limit (timeout)' : 'nothing for '.self::seconds($this->idleTimeout).' (idleTimeout)';

        return new ConnectionLostException("{$this->where} stopped sending its answer to {$this->op}: $limit", ['kind' => 'timeout', 'reason' => 'timeout', 'exitCode' => 255, 'argv' => [$this->op]], $e);
    }

    /** "1 s", "1.5 s", "no limit". */
    public static function seconds(?float $s): string
    {
        return null === $s ? 'no limit' : rtrim(rtrim(\sprintf('%.3f', $s), '0'), '.').' s';
    }

    public function read(int $length): string
    {
        if (null !== $this->raw) {
            return $this->readRaw($length);
        }
        try {
            $data = $this->inner->read($length);
        } catch (GaiaDeskException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            if ($e instanceof NetworkException && $e->timedOut) {
                throw $this->stalled($e);
            }
            $this->close();
            throw new ConnectionLostException("the answer to {$this->op} broke off: {$e->getMessage()}", ['kind' => 'connection_lost', 'reason' => 'incomplete', 'exitCode' => 255, 'argv' => [$this->op]], $e);
        }
        $this->position += \strlen($data);

        return $data;
    }

    private function readRaw(int $length): string
    {
        if ($this->rawEnded || !\is_resource($this->raw)) {
            return '';
        }
        try {
            $data = SocketClient::readSome($this->raw, max(1, $length), $this->idleTimeout);
        } catch (ReadTimedOut $e) {
            throw $this->stalled($e);
        }
        if (null === $data) {
            $this->rawEnded = true;

            return '';
        }
        $this->position += \strlen($data);

        return $data;
    }

    public function eof(): bool
    {
        if (null !== $this->raw) {
            return $this->rawEnded || !\is_resource($this->raw) || feof($this->raw);
        }

        return $this->inner->eof();
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(65536);
        }

        return $out;
    }

    public function close(): void
    {
        if (null !== $this->raw) {
            if (\is_resource($this->raw)) {
                fclose($this->raw);
            }
            $this->raw = null;
            $this->rawEnded = true;

            return;
        }
        $this->inner->close();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null !== $this->raw || $this->rawEnded ? null : $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('an answer is read once, as it arrives');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('an answer is read once, as it arrives');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('an answer is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
