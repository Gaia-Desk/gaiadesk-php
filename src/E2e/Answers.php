<?php

declare(strict_types=1);

namespace GaiaDesk\E2e;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Internal\Errors;
use GaiaDesk\Internal\Json;
use GaiaDesk\Stream\Utf8Decoder;
use Psr\Http\Message\StreamInterface;

/**
 * Sealed answers turned back into exactly what the plaintext call answers: JSON results,
 * error envelopes, stream events (mapped as the server maps a desk's events) and file
 * bytes; and an upload's body sealed into input frames.
 *
 * @internal
 *
 * @phpstan-import-type DeskEvent from CallerSeal
 */
final class Answers
{
    /**
     * A sealed event opened, or the ProtocolException for one that does not.
     *
     * @param list<string> $argv
     *
     * @return DeskEvent
     */
    public static function open(CallerSeal $seal, mixed $frame, array $argv): array
    {
        try {
            return $seal->openDeskEvent($frame);
        } catch (E2eOpenException $e) {
            throw new ProtocolException("the desk's end-to-end encrypted answer did not open: {$e->getMessage()}", ['kind' => 'protocol', 'reason' => $e->reason, 'argv' => $argv, 'exitCode' => 255]);
        }
    }

    /**
     * @param array<array-key, mixed> $json
     *
     * @return list<mixed>|null
     */
    private static function eventsOf(array $json): ?array
    {
        $e = null;
        if (Json::isObject($json['e2e'] ?? null)) {
            $e = $json['e2e'];
        } elseif (\is_array($json['error'] ?? null) && Json::isObject($json['error']['e2e'] ?? null)) {
            $e = $json['error']['e2e'];
        }
        if (!\is_array($e) || !\is_array($e['events'] ?? null) || !array_is_list($e['events'])) {
            return null;
        }

        return $e['events'];
    }

    /**
     * An error envelope with the desk's real message: a desk's error comes with a
     * placeholder `message` and `e2e.events`, whose last opens to the `error`. An
     * envelope without events (the server's own error) is as it is.
     *
     * @param list<string> $argv
     */
    public static function openErrorEnvelope(mixed $json, CallerSeal $seal, array $argv): mixed
    {
        if (!\is_array($json) || !\is_array($json['error'] ?? null)) {
            return $json;
        }
        $events = self::eventsOf($json);
        if (null === $events) {
            return $json;
        }
        $error = $json['error'];
        unset($error['e2e']);
        $last = null;
        try {
            foreach ($events as $f) {
                $last = self::open($seal, $f, $argv);
            }
        } catch (ProtocolException) {
            $last = null;
        }
        $placeholder = \is_string($error['message'] ?? null) ? $error['message'] : 'the desk reported an error';
        $error['message'] = null !== $last && 'error' === $last['event'] ? $last['message'] : "$placeholder (its end-to-end encrypted message did not open)";
        $out = $json;
        unset($out['e2e']);
        $out['error'] = $error;

        return $out;
    }

    /**
     * A sealed JSON answer (`{"e2e": {"events"}}`): the result the plaintext call
     * answers; a held body's envelope, opened.
     *
     * @param list<string> $argv
     */
    public static function openAnswer(mixed $json, CallerSeal $seal, array $argv): mixed
    {
        if (null !== Errors::envelope($json)) {
            return self::openErrorEnvelope($json, $seal, $argv);
        }
        $events = \is_array($json) ? self::eventsOf($json) : null;
        if (null === $events || [] === $events) {
            throw new ProtocolException('the GaiaDesk API answered an end-to-end encrypted operation without sealed events', ['kind' => 'protocol', 'reason' => 'e2e_unsealed_answer', 'argv' => $argv, 'json' => $json, 'exitCode' => 255]);
        }
        $last = null;
        foreach ($events as $f) {
            $last = self::open($seal, $f, $argv);
        }
        if ('exit' === $last['event']) {
            return $last['result'];
        }
        if ('error' === $last['event']) {
            throw self::deskError($last, $seal->desk, $argv);
        }
        throw new ProtocolException("the desk's sealed answer has no result", ['kind' => 'protocol', 'reason' => 'e2e_malformed', 'argv' => $argv, 'exitCode' => 255]);
    }

    /**
     * The HTTP status `/v1` gives a desk's error (the protocol's `desk_error_status`).
     *
     * @return array{status: int, kind: string}
     */
    public static function deskErrorStatus(string $kind, ?string $reason): array
    {
        return match ($kind) {
            'usage' => ['status' => 400, 'kind' => $kind],
            'refused' => ['status' => 'desk_busy' === $reason ? 429 : ('e2e_required' === $reason ? 409 : 403), 'kind' => $kind],
            'unreachable' => ['status' => 409, 'kind' => $kind],
            'connection_lost', 'protocol' => ['status' => 502, 'kind' => $kind],
            default => ['status' => 422, 'kind' => 'failed'],
        };
    }

    /**
     * A desk's opened `error` event as the error the plaintext call throws.
     *
     * @param array{event: 'error', kind: string, message: string, reason?: string} $e
     * @param list<string>                                                          $argv
     */
    public static function deskError(array $e, string $desk, array $argv): GaiaDeskException
    {
        ['status' => $status, 'kind' => $kind] = self::deskErrorStatus($e['kind'], $e['reason'] ?? null);
        $reason = $e['reason'] ?? $kind;
        $json = ['error' => ['kind' => $kind, 'message' => $e['message'], 'reason' => $reason, 'desk' => $desk]];

        return Errors::forKind($kind, $e['message'], [
            'kind' => Errors::sdkKind($kind, $reason), 'reason' => $reason, 'desk' => $desk, 'status' => $status,
            'argv' => $argv, 'json' => $json, 'exitCode' => Errors::deskOpExit($kind),
        ]);
    }

    /**
     * A sealed download (`application/x-ndjson`, one sealed event per line), read as it
     * arrives: each piece of the file handed to `$write`; the `exit` event's result.
     *
     * @param callable(string): void $write
     * @param list<string>           $argv
     */
    public static function openDownload(StreamInterface $body, CallerSeal $seal, callable $write, array $argv): mixed
    {
        $buf = '';
        $lines = static function () use ($body, &$buf): \Generator {
            while (true) {
                while (false !== ($i = strpos($buf, "\n"))) {
                    $line = substr($buf, 0, $i);
                    $buf = (string) substr($buf, $i + 1);
                    yield $line;
                }
                if ($body->eof()) {
                    break;
                }
                try {
                    $buf .= $body->read(65536);
                } catch (GaiaDeskException $e) {
                    throw $e; // the answer stalled or broke off: the SDK's own error says which
                } catch (\RuntimeException) {
                    break; // the transfer broke: the download is incomplete
                }
            }
            if ('' !== $buf) {
                yield $buf;
                $buf = '';
            }
        };
        foreach ($lines() as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $frame = Json::decode($line, false);
            if (false === $frame) {
                throw new ProtocolException('a sealed download has a line that is not JSON', ['kind' => 'protocol', 'reason' => 'e2e_malformed', 'argv' => $argv, 'exitCode' => 255]);
            }
            $e = self::open($seal, $frame, $argv);
            if ('stdout' === $e['event']) {
                $b = Crypto::b64decode($e['data']);
                if (null === $b) {
                    throw new ProtocolException('a sealed download carries bytes that are not base64', ['kind' => 'protocol', 'reason' => 'e2e_malformed', 'argv' => $argv, 'exitCode' => 255]);
                }
                $write($b);
            } elseif ('error' === $e['event']) {
                throw self::deskError($e, $seal->desk, $argv);
            } elseif ('exit' === $e['event']) {
                return $e['result'];
            }
        }
        throw new ConnectionLostException('the download ended before the desk said it was complete', ['kind' => 'connection_lost', 'reason' => 'incomplete', 'argv' => $argv, 'desk' => $seal->desk, 'exitCode' => 255]);
    }

    /**
     * An upload's body, sealed: one input frame per line, at most 48 KiB of the file each,
     * the last flagged. Written to a temporary stream (spilled to disk past 2 MB).
     *
     * @return resource
     */
    public static function sealUpload(CallerSeal $seal, StreamInterface $source, int $size)
    {
        $out = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (false === $out) {
            throw new \RuntimeException('cannot open a temporary buffer for the sealed upload');
        }
        if ($source->isSeekable()) {
            $source->rewind();
        }
        $sent = 0;
        do {
            $chunk = '';
            while (\strlen($chunk) < Crypto::INPUT_CHUNK && $sent + \strlen($chunk) < $size && !$source->eof()) {
                $chunk .= $source->read(Crypto::INPUT_CHUNK - \strlen($chunk));
            }
            $sent += \strlen($chunk);
            $last = $sent >= $size || $source->eof();
            fwrite($out, Json::encode($seal->sealInput($last, $chunk))."\n");
        } while (!$last);
        rewind($out);

        return $out;
    }

    /**
     * A sealed stream's events, opened (in order) and mapped as the API maps a desk's
     * events (`exec`: stdout, stderr, exit, error; `logs`: output, end, interrupted,
     * error). A plaintext `error` (the server's: the desk was lost) passes; any other
     * plaintext output in a sealed stream is refused.
     *
     * Events are `['event' => name, ...fields]`; output events carry raw bytes in `data`.
     *
     * @param iterable<array<string, mixed>> $events the stream's events, JSON-decoded, each with its `event`
     * @param 'exec'|'logs'                  $kind
     * @param list<string>                   $argv
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function unseal(iterable $events, CallerSeal $seal, string $kind, array $argv): \Generator
    {
        $dec = ['stdout' => new Utf8Decoder(), 'stderr' => new Utf8Decoder()];
        foreach ($events as $ev) {
            $name = $ev['event'] ?? '';
            if ('error' === $name) {
                yield $ev;

                continue;
            }
            if ('sealed' !== $name) {
                if (\in_array($name, ['stdout', 'stderr', 'exit', 'output', 'end', 'interrupted', 'message'], true)) {
                    throw new ProtocolException("the GaiaDesk API sent a plaintext `$name` event in an end-to-end encrypted stream", ['kind' => 'protocol', 'reason' => 'e2e_unsealed_answer', 'argv' => $argv, 'exitCode' => 255]);
                }

                continue;
            }
            $frame = $ev;
            unset($frame['event']);
            $e = self::open($seal, $frame, $argv);
            if ('stdout' === $e['event'] || 'stderr' === $e['event']) {
                $b = Crypto::b64decode($e['data']);
                if (null === $b) {
                    continue; // not base64: the desk's bug, dropped (as the server does)
                }
                $stream = 'logs' === $kind ? 'stdout' : $e['event'];
                $text = $dec[$stream]->decode($b);
                if ('' !== $text) {
                    yield 'logs' === $kind ? ['event' => 'output', 'data' => $text] : ['event' => $stream, 'data' => $text];
                }
            } elseif ('exit' === $e['event']) {
                /** @var array<string, mixed> $result */
                $result = Json::isObject($e['result']) && \is_array($e['result']) ? $e['result'] : [];
                if ('exec' === $kind) {
                    foreach (['stdout', 'stderr'] as $s) {
                        $t = $dec[$s]->decode('', true);
                        if ('' !== $t) {
                            yield ['event' => $s, 'data' => $t];
                        }
                    }
                    unset($result['stdout'], $result['stderr'], $result['truncated']);
                    yield ['event' => 'exit'] + $result;
                } else {
                    $t = $dec['stdout']->decode('', true);
                    if ('' !== $t) {
                        yield ['event' => 'output', 'data' => $t];
                    }
                    yield true === ($result['interrupted'] ?? null) ? ['event' => 'interrupted'] : ['event' => 'end', 'job' => $result['job'] ?? null];
                }
            } else {
                $error = ['kind' => $e['kind'], 'message' => $e['message'], 'desk' => $seal->desk];
                if (isset($e['reason'])) {
                    $error['reason'] = $e['reason'];
                }
                yield 'exec' === $kind ? ['event' => 'error', 'exit' => 'refused' === $e['kind'] ? 254 : 255, 'error' => $error] : ['event' => 'error', 'error' => $error];
            }
        }
    }
}
