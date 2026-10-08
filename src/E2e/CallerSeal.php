<?php

declare(strict_types=1);

namespace GaiaDesk\E2e;

use GaiaDesk\Internal\Json;

/**
 * The caller's side of one operation after its request is sealed: its input going up
 * (sealed in order from `seq` 0), the desk's events coming back (opened in order from
 * `seq` 0; a gap, a repeat or a change fails).
 *
 * @phpstan-import-type SealedFrame from Crypto
 * @phpstan-import-type OpKeys from Crypto
 *
 * @phpstan-type DeskEvent array{event: 'stdout'|'stderr', data: string}|array{event: 'exit', result: mixed}|array{event: 'error', kind: string, message: string, reason?: string}
 */
final class CallerSeal
{
    private int $nextInput = 0;
    private int $nextEvent = 0;

    /**
     * @param OpKeys $keys
     */
    public function __construct(private readonly array $keys, public readonly string $desk, public readonly string $op)
    {
    }

    /**
     * The next piece of input (`$last` on the final one, which may be empty), with a given nonce.
     *
     * @return SealedFrame
     */
    public function sealInputWith(string $nonce, bool $last, string $data): array
    {
        $seq = $this->nextInput++;
        $ct = Crypto::aeadSeal($this->keys['input'], $nonce, Crypto::associatedData('input', $this->desk, $this->op, $seq), ($last ? "\1" : "\0").$data);

        return ['seq' => $seq, 'nonce' => Crypto::b64url($nonce), 'ciphertext' => Crypto::b64url($ct)];
    }

    /**
     * The next piece of input, with a random nonce.
     *
     * @return SealedFrame
     */
    public function sealInput(bool $last, string $data): array
    {
        return $this->sealInputWith(random_bytes(24), $last, $data);
    }

    /** Open the desk's next event (it must be the next in order): its plaintext. */
    public function openEvent(mixed $frame): string
    {
        if (!\is_array($frame) || !\is_int($frame['seq'] ?? null) || !\is_string($frame['nonce'] ?? null) || !\is_string($frame['ciphertext'] ?? null)) {
            throw new E2eOpenException('e2e_malformed', 'a sealed event is malformed');
        }
        $seq = $frame['seq'];
        if ($seq !== $this->nextEvent) {
            throw new E2eOpenException('e2e_decrypt_failed', "a sealed event is out of order (got $seq, expected {$this->nextEvent})");
        }
        $plain = Crypto::aeadOpen($this->keys['event'], $frame['nonce'], $frame['ciphertext'], Crypto::associatedData('event', $this->desk, $this->op, $seq));
        ++$this->nextEvent;

        return $plain;
    }

    /**
     * Open the next event as the desk event it carries
     * (`{"event": "stdout" | "stderr" | "exit" | "error", …}`).
     *
     * @return DeskEvent
     */
    public function openDeskEvent(mixed $frame): array
    {
        $plain = $this->openEvent($frame);
        if (!mb_check_encoding($plain, 'UTF-8')) {
            throw new E2eOpenException('e2e_malformed', 'a sealed event is not JSON');
        }
        $v = Json::decode($plain, false);
        if (!\is_array($v)) {
            throw new E2eOpenException('e2e_malformed', 'a sealed event is not JSON');
        }
        $event = $v['event'] ?? null;
        if (('stdout' === $event || 'stderr' === $event) && \is_string($v['data'] ?? null)) {
            return ['event' => $event, 'data' => $v['data']];
        }
        if ('exit' === $event) {
            return ['event' => 'exit', 'result' => $v['result'] ?? null];
        }
        if ('error' === $event) {
            $e = ['event' => 'error', 'kind' => \is_string($v['kind'] ?? null) ? $v['kind'] : 'failed', 'message' => \is_string($v['message'] ?? null) ? $v['message'] : ''];
            if (\is_string($v['reason'] ?? null)) {
                $e['reason'] = $v['reason'];
            }

            return $e;
        }
        throw new E2eOpenException('e2e_malformed', 'a sealed event is not a desk event');
    }
}
