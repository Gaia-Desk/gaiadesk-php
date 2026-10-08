<?php

declare(strict_types=1);

namespace GaiaDesk\E2e;

use GaiaDesk\Internal\Json;

/**
 * End-to-end encrypted desk operations, v1: the caller's side of the sealing the
 * GaiaDesk API relays without reading (docs/api "End-to-end encryption"; the reference
 * is the protocol crate's e2e.rs, whose fixed test vectors this class reproduces byte
 * for byte).
 *
 * Per operation: an ephemeral X25519 key pair; shared = X25519(eph, desk);
 * prk = HKDF-SHA256-Extract("gaiadesk desk-op e2e v1", shared); one key per use
 * (`request`, `input`, `event`) = HKDF-Expand(prk, label 0x00 eph_pub desk_pub, 32).
 * Every message is XChaCha20-Poly1305 with a random 24-byte nonce and associated data
 * naming the use, the desk, the operation and (for the streams) the message's place.
 *
 * Nothing here is home-made: X25519 and XChaCha20-Poly1305 are libsodium's (ext-sodium),
 * HKDF-SHA256 is PHP's hash_hkdf, randomness is random_bytes.
 *
 * @phpstan-type SealedRequest array{v: int, pub: string, nonce: string, ciphertext: string}
 * @phpstan-type SealedFrame array{seq: int, nonce: string, ciphertext: string}
 * @phpstan-type OpKeys array{request: string, input: string, event: string}
 */
final class Crypto
{
    public const FEATURE = 'desk_op_e2e';
    public const VERSION = 1;
    /** The header that carries a sealed request on a call without a JSON body. */
    public const HEADER = 'GaiaDesk-E2E';
    public const FRAMES_CONTENT_TYPE = 'application/x-ndjson';
    public const HKDF_SALT = 'gaiadesk desk-op e2e v1';
    /** The most file bytes one sealed input frame carries (48 KiB). */
    public const INPUT_CHUNK = 49152;

    // ───────────────────────────── base64 ─────────────────────────────

    /** base64url without padding: every binary field of the envelope. */
    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Standard base64 with padding (a desk event's `data`). */
    public static function b64encode(string $bytes): string
    {
        return base64_encode($bytes);
    }

    /** Standard or url-safe base64, padded or not; null when it is not base64. */
    public static function b64decode(string $s): ?string
    {
        $t = rtrim(strtr(trim($s), '-_', '+/'), '=');
        if (1 !== preg_match('#^[A-Za-z0-9+/]*$#D', $t) || 1 === \strlen($t) % 4) {
            return null;
        }
        $pad = (4 - \strlen($t) % 4) % 4;
        $out = base64_decode($t.str_repeat('=', $pad), true);

        return false === $out ? null : $out;
    }

    // ───────────────────────────── primitives ─────────────────────────────

    /** The X25519 public key of a 32-byte secret. */
    public static function x25519Public(string $secret): string
    {
        self::length($secret, 32, 'an X25519 secret');

        return sodium_crypto_scalarmult_base($secret);
    }

    /** X25519, refusing a non-contributory (all-zero) result. */
    public static function x25519(string $secret, string $public): string
    {
        self::length($secret, 32, 'an X25519 secret');
        if (32 !== \strlen($public)) {
            throw new E2eOpenException('e2e_malformed', 'an X25519 public key is 32 bytes');
        }
        try {
            $shared = sodium_crypto_scalarmult($secret, $public);
        } catch (\SodiumException) {
            // libsodium refuses the all-zero result itself.
            throw new E2eOpenException('e2e_weak_key', 'the key exchange gave no shared secret (a low-order key)');
        }
        if (str_repeat("\0", 32) === $shared) {
            throw new E2eOpenException('e2e_weak_key', 'the key exchange gave no shared secret (a low-order key)');
        }

        return $shared;
    }

    /** The associated data: `"gaiadesk-e2e/v1 <use>" 0 desk 0 op`, then `0 seq` (u64 big-endian) for input and events. */
    public static function associatedData(string $use, string $desk, string $op, ?int $seq = null): string
    {
        $head = "gaiadesk-e2e/v1 $use\0$desk\0$op";
        if (null === $seq) {
            return $head;
        }

        return $head."\0".pack('J', $seq);
    }

    /**
     * One operation's three keys, from the exchange's shared secret and both public keys.
     *
     * @return OpKeys
     */
    public static function deriveKeys(string $shared, string $ephPub, string $deskPub): array
    {
        $key = static fn (string $label): string => hash_hkdf('sha256', $shared, 32, $label."\0".$ephPub.$deskPub, self::HKDF_SALT);

        return ['request' => $key('request'), 'input' => $key('input'), 'event' => $key('event')];
    }

    /** XChaCha20-Poly1305 encryption: the ciphertext and its tag. */
    public static function aeadSeal(string $key, string $nonce, string $aad, string $plaintext): string
    {
        self::length($key, 32, 'a key');
        self::length($nonce, 24, 'a nonce');

        return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
    }

    /** XChaCha20-Poly1305 decryption of base64url fields; E2eOpenException when it does not authenticate. */
    public static function aeadOpen(string $key, string $nonce, string $ciphertext, string $aad): string
    {
        $n = self::b64decode($nonce);
        $c = self::b64decode($ciphertext);
        if (null === $n || 24 !== \strlen($n) || null === $c || \strlen($c) < 16) {
            throw new E2eOpenException('e2e_malformed', 'a sealed message is malformed');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($c, $aad, $n, $key);
        if (false === $plain) {
            throw new E2eOpenException('e2e_decrypt_failed', 'a sealed message did not open: it was altered, reordered, or sealed for another desk or operation');
        }

        return $plain;
    }

    // ───────────────────────────── requests ─────────────────────────────

    /**
     * Seal `$plaintext` (the inner request's JSON) for desk `$desk` (its key `$deskPub`)
     * as operation `$op`, with a given ephemeral secret and nonce: the test vectors'
     * entry point. Never reuse either.
     *
     * @return array{request: SealedRequest, seal: CallerSeal}
     */
    public static function sealRequestWith(string $eph, string $nonce, string $deskPub, string $desk, string $op, string $plaintext): array
    {
        $ephPub = self::x25519Public($eph);
        $keys = self::deriveKeys(self::x25519($eph, $deskPub), $ephPub, $deskPub);
        $ct = self::aeadSeal($keys['request'], $nonce, self::associatedData('request', $desk, $op), $plaintext);

        return [
            'request' => ['v' => self::VERSION, 'pub' => self::b64url($ephPub), 'nonce' => self::b64url($nonce), 'ciphertext' => self::b64url($ct)],
            'seal' => new CallerSeal($keys, $desk, $op),
        ];
    }

    /**
     * Seal a desk operation's request (`['op' => …]`) now: `{"v":1,"ts":<now>,"request":…}`
     * under a fresh ephemeral key.
     *
     * @param array<string, mixed> $request
     *
     * @return array{request: SealedRequest, seal: CallerSeal}
     */
    public static function sealRequest(string $deskPub, string $desk, string $op, array $request, ?int $now = null): array
    {
        $inner = Json::encode(['v' => self::VERSION, 'ts' => $now ?? time(), 'request' => $request]);

        return self::sealRequestWith(random_bytes(32), random_bytes(24), $deskPub, $desk, $op, $inner);
    }

    /**
     * The GaiaDesk-E2E header value of a sealed request: base64url of its JSON.
     *
     * @param SealedRequest $r
     */
    public static function requestHeader(array $r): string
    {
        return self::b64url(Json::encode(['v' => $r['v'], 'pub' => $r['pub'], 'nonce' => $r['nonce'], 'ciphertext' => $r['ciphertext']]));
    }

    /** A desk key as given (`e2e_pub`, base64url): its 32 bytes, or null. */
    public static function deskKey(mixed $s): ?string
    {
        if (!\is_string($s)) {
            return null;
        }
        $k = self::b64decode($s);

        return null !== $k && 32 === \strlen($k) ? $k : null;
    }

    private static function length(string $v, int $n, string $what): void
    {
        if ($n !== \strlen($v)) {
            throw new \InvalidArgumentException("$what is $n bytes");
        }
    }
}
