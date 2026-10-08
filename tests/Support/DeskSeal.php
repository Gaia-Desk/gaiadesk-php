<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use GaiaDesk\E2e\Crypto;

/**
 * A desk's side of end-to-end encrypted desk operations, for the tests only: open a
 * sealed request with the desk's static secret, seal events back, open input frames.
 * Built from the SDK's own primitives, in the order the protocol's crypto.rs gives.
 */
final class DeskSeal
{
    private int $nextInput = 0;
    private int $nextEvent = 0;

    /** @param array{request: string, input: string, event: string} $keys */
    public function __construct(private readonly array $keys, public readonly string $desk, public readonly string $op)
    {
    }

    /** @return array{seq: int, nonce: string, ciphertext: string} */
    public function sealEventWith(string $nonce, string $plaintext): array
    {
        $seq = $this->nextEvent++;
        $ct = Crypto::aeadSeal($this->keys['event'], $nonce, Crypto::associatedData('event', $this->desk, $this->op, $seq), $plaintext);

        return ['seq' => $seq, 'nonce' => Crypto::b64url($nonce), 'ciphertext' => Crypto::b64url($ct)];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{seq: int, nonce: string, ciphertext: string}
     */
    public function sealEvent(array $event): array
    {
        return $this->sealEventWith(random_bytes(24), (string) json_encode($event, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array{last: bool, data: string}
     */
    public function openInput(array $f): array
    {
        if (($f['seq'] ?? null) !== $this->nextInput) {
            throw new \RuntimeException('input out of order');
        }
        $plain = Crypto::aeadOpen($this->keys['input'], (string) $f['nonce'], (string) $f['ciphertext'], Crypto::associatedData('input', $this->desk, $this->op, $this->nextInput));
        ++$this->nextInput;
        if ("\0" !== $plain[0] && "\1" !== $plain[0]) {
            throw new \RuntimeException('bad input flag');
        }

        return ['last' => "\1" === $plain[0], 'data' => substr($plain, 1)];
    }

    /**
     * Open a sealed request to `$desk` as `$op` with the desk's secret: its plaintext and the seal for the rest.
     *
     * @param array<string, mixed> $req
     *
     * @return array{plain: string, seal: DeskSeal}
     */
    public static function openRequest(string $deskSecret, string $desk, string $op, array $req): array
    {
        if (1 !== ($req['v'] ?? null)) {
            throw new \RuntimeException('bad version');
        }
        $ephPub = Crypto::b64decode((string) ($req['pub'] ?? ''));
        if (null === $ephPub || 32 !== \strlen($ephPub)) {
            throw new \RuntimeException('bad pub');
        }
        $deskPub = Crypto::x25519Public($deskSecret);
        $keys = Crypto::deriveKeys(Crypto::x25519($deskSecret, $ephPub), $ephPub, $deskPub);
        $plain = Crypto::aeadOpen($keys['request'], (string) $req['nonce'], (string) $req['ciphertext'], Crypto::associatedData('request', $desk, $op));

        return ['plain' => $plain, 'seal' => new self($keys, $desk, $op)];
    }
}
