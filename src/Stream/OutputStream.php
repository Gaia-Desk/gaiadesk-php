<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\Internal\Errors;

/**
 * A streamed command (`execStream`) or a followed job's log (`followJobLogs`): its
 * output as it arrives, then how it ended.
 *
 * ```php
 * $s = $gd->execStream('123456789', 'make test');
 * foreach ($s as $chunk) {            // Chunk: ->stream ('stdout'|'stderr'), ->data (bytes)
 *     echo $chunk->data;
 * }
 * $exit = $s->wait();                 // StreamExit: ->exitCode, ->result, ->error
 * ```
 *
 * The request is made when the stream is created; a failure to start it (refused,
 * unreachable, ...) is not thrown but becomes how it ended ({@see wait()}), with nothing
 * to iterate. Iterate once; {@see wait()} reads what was not iterated (discarding its
 * output) and returns how it ended. {@see cancel()} hangs up: the server stops the
 * command (or stops following the job, which goes on).
 *
 * @implements \IteratorAggregate<int, Chunk>
 */
final class OutputStream implements \IteratorAggregate
{
    /** @var \Generator<int, array<string, mixed>>|null */
    private ?\Generator $events = null;
    /** @var (\Closure(): void)|null */
    private ?\Closure $close = null;
    private ?StreamExit $exit = null;
    /** @var array<string, mixed>|null the exec stream's last `exit` / `error` event */
    private ?array $last = null;
    private bool $started = false;
    private bool $cancelled = false;

    /**
     * @param 'exec'|'logs'                                                                                $kind
     * @param \Closure(): array{events: \Generator<int, array<string, mixed>>, close: \Closure(): void} $start makes the request (throws the typed error for an HTTP failure)
     *
     * @internal made by the client
     */
    public function __construct(private readonly string $op, private readonly string $kind, \Closure $start, private readonly string $jobName = '')
    {
        try {
            $s = $start();
            $this->events = $s['events'];
            $this->close = $s['close'];
        } catch (GaiaDeskException $e) {
            $this->exit = self::exitForError($e);
        }
    }

    public function __destruct()
    {
        $this->closeBody();
    }

    /** What was called: `POST /desks/123456789/exec`. */
    public function op(): string
    {
        return $this->op;
    }

    /**
     * The output as it arrives.
     *
     * @return \Generator<int, Chunk>
     */
    public function getIterator(): \Generator
    {
        if ($this->started) {
            throw new UsageException('a stream is iterated once', ['kind' => 'usage', 'argv' => [$this->op]]);
        }
        $this->started = true;
        while (null !== ($c = $this->next())) {
            yield $c;
        }
    }

    /**
     * The output as text, a character split between two pieces carried to the next.
     *
     * @return \Generator<int, array{stream: 'stdout'|'stderr', text: string}>
     */
    public function text(): \Generator
    {
        $dec = ['stdout' => new Utf8Decoder(), 'stderr' => new Utf8Decoder()];
        foreach ($this as $c) {
            $t = $dec[$c->stream]->decode($c->data);
            if ('' !== $t) {
                yield ['stream' => $c->stream, 'text' => $t];
            }
        }
        foreach ($dec as $s => $d) {
            $t = $d->decode('', true);
            if ('' !== $t) {
                yield ['stream' => $s, 'text' => $t];
            }
        }
    }

    /** Read whatever was not iterated (its output discarded) and return how it ended. */
    public function wait(): StreamExit
    {
        $this->started = true;
        while (null !== $this->next()) {
        }

        return $this->exit ?? self::lost('the event stream ended before it said how');
    }

    /** Has it ended (and {@see wait()} returns at once)? */
    public function done(): bool
    {
        return null !== $this->exit;
    }

    /** Hang up: the server stops the command (or stops following the job; the job goes on). Ends with 130. */
    public function cancel(): void
    {
        if (null !== $this->exit) {
            return;
        }
        $this->cancelled = true;
        $this->closeBody();
        $this->exit = new StreamExit(130, 'interrupted');
    }

    /** Stdin cannot be written over the API (give `stdin` as text up front). */
    public function write(string $data): never
    {
        throw new UsageException('stdin cannot be written to a command over the API transport (give `stdin` as text up front)', ['kind' => 'usage', 'argv' => [$this->op]]);
    }

    private function closeBody(): void
    {
        $c = $this->close;
        $this->close = null;
        if (null !== $c) {
            try {
                $c();
            } catch (\Throwable) {
            }
        }
    }

    /** The next chunk of output, or null at the end (when {@see $exit} is set). */
    private function next(): ?Chunk
    {
        while (null === $this->exit && null !== $this->events) {
            try {
                if (!$this->events->valid()) {
                    $this->finish();
                    break;
                }
                $ev = $this->events->current();
                $this->events->next();
            } catch (GaiaDeskException $e) {
                $this->closeBody();
                $this->exit = $this->cancelled ? new StreamExit(130, 'interrupted') : self::exitForError($e);
                break;
            } catch (\Throwable $e) {
                $this->closeBody();
                $this->exit = $this->cancelled ? new StreamExit(130, 'interrupted') : self::exitForError(new UnreachableException('the event stream broke off: '.$e->getMessage(), ['kind' => 'network', 'reason' => 'network', 'exitCode' => 255, 'argv' => [$this->op]], $e));
                break;
            }
            $c = 'exec' === $this->kind ? $this->execEvent($ev) : $this->logEvent($ev);
            if (null !== $c) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ev
     */
    private function execEvent(array $ev): ?Chunk
    {
        $name = $ev['event'] ?? null;
        if (('stdout' === $name || 'stderr' === $name) && \is_string($ev['data'] ?? null)) {
            return '' === $ev['data'] ? null : new Chunk($name, $ev['data']);
        }
        if ('exit' === $name || 'error' === $name) {
            $this->last = $ev;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ev
     */
    private function logEvent(array $ev): ?Chunk
    {
        $name = $ev['event'] ?? null;
        if ('output' === $name && \is_string($ev['data'] ?? null)) {
            return '' === $ev['data'] ? null : new Chunk('stdout', $ev['data']);
        }
        if ('end' === $name) {
            $job = \is_array($ev['job'] ?? null) ? $ev['job'] : [];
            $jn = \is_string($job['name'] ?? null) ? $job['name'] : $this->jobName;
            $tail = \is_int($job['exit_code'] ?? null) ? "job $jn exited (exit {$job['exit_code']})" : "job $jn ".(\is_string($job['state'] ?? null) ? $job['state'] : 'ended');
            $this->end(new StreamExit(0, $tail, ['job' => $ev['job'] ?? null]));
        } elseif ('interrupted' === $name) {
            $this->end(new StreamExit(0, 'stopped following; the job goes on', ['interrupted' => true]));
        } elseif ('error' === $name) {
            $error = Errors::execError($ev['error'] ?? null) ?? ['kind' => 'protocol', 'message' => 'the desk reported an error'];
            $this->end(new StreamExit(Errors::deskOpExit($error['kind']), $error['message'], null, $error));
        }

        return null;
    }

    private function end(StreamExit $exit): void
    {
        $this->exit = $exit;
        $this->closeBody();
    }

    /** The events ran out: how the exec stream's last event says it ended. */
    private function finish(): void
    {
        $this->closeBody();
        if (null !== $this->exit) {
            return;
        }
        if ('logs' === $this->kind) {
            $this->exit = self::lost('the event stream ended before the job did');

            return;
        }
        $last = $this->last;
        if (null === $last) {
            $this->exit = self::lost('the event stream ended before the command did');

            return;
        }
        $exitCode = \is_int($last['exit'] ?? null) ? $last['exit'] : null;
        if ('exit' === $last['event']) {
            $result = $last;
            unset($result['event']);
            $error = Errors::execError($result['error'] ?? null);
            $result['error'] = $error;
            $this->exit = new StreamExit($exitCode, null !== $error ? $error['message'] : '', $result, $error);

            return;
        }
        $error = Errors::execError($last['error'] ?? null);
        $this->exit = new StreamExit($exitCode ?? (null !== $error ? Errors::deskOpExit($error['kind']) : 255), null !== $error ? $error['message'] : '', null, $error);
    }

    private static function lost(string $message): StreamExit
    {
        return new StreamExit(255, $message, null, ['kind' => 'connection_lost', 'message' => $message]);
    }

    /** How an error that ended (or prevented) a stream ends it. */
    public static function exitForError(GaiaDeskException $e): StreamExit
    {
        $env = Errors::envelope($e->getJson());
        if (null !== $env) {
            $error = ['kind' => $env['kind'], 'message' => '' !== $env['message'] ? $env['message'] : $e->getMessage()];
            if (isset($env['reason'])) {
                $error['reason'] = $env['reason'];
            }
            if (isset($env['desk'])) {
                $error['desk'] = $env['desk'];
            }
        } else {
            $error = ['kind' => 'network' === $e->getKind() ? 'unreachable' : $e->getKind(), 'message' => $e->getMessage()];
            if (null !== $e->getReason()) {
                $error['reason'] = $e->getReason();
            }
            if (null !== $e->getDesk()) {
                $error['desk'] = $e->getDesk();
            }
        }

        return new StreamExit($e->getExitCode(), $e->getMessage(), null, $error);
    }
}
