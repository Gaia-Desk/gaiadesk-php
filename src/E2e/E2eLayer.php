<?php

declare(strict_types=1);

namespace GaiaDesk\E2e;

use GaiaDesk\Exception\E2eException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UsageException;

/**
 * End-to-end encryption on the hosted API: whether and to which key an operation is
 * sealed (the desk's `e2e_pub` from `GET /desks/{id}`, cached; pinned keys; the mode; a
 * wake when a desk that must be sealed to lists no key), and the one retry each for
 * `e2e_required` and `e2e_decrypt_failed`.
 *
 * @internal
 *
 * @phpstan-import-type SealedRequest from Crypto
 *
 * @phpstan-type Sealed array{request: SealedRequest, seal: CallerSeal}
 * @phpstan-type KeyInfo array{pub: ?string, required: bool, why: string}
 * @phpstan-type Call array{deskToken?: ?string, wake?: ?int}
 */
final class E2eLayer
{
    public const MODES = ['auto', 'require', 'off'];
    private const KEY_TTL = 300.0;
    private const NO_KEY_TTL = 30.0;
    private const DEFAULT_WAKE_S = 30;

    /** @var array<string, true> desks warned about (per base URL), once per process */
    private static array $warned = [];

    /** @var array<string, string> */
    private array $pins = [];
    /** @var array<string, array{at: float, info: KeyInfo}> */
    private array $cache = [];
    /** @var \Closure(string): void */
    private readonly \Closure $warn;
    /** @var \Closure(string, string, array{deskToken?: ?string, json?: mixed}): mixed */
    private readonly \Closure $api;

    /**
     * @param array<array-key, string>                                             $pins desk id => e2e_pub (base64url; PHP keeps a numeric id as an int key)
     * @param callable(string, string, array{deskToken?: ?string, json?: mixed}): mixed $api  the API's own calls (lookup, wake), never sealed
     * @param callable(string): void|null                                          $warn
     */
    public function __construct(
        public readonly string $mode,
        array $pins,
        callable $api,
        private readonly string $baseUrl,
        ?callable $warn = null,
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            throw new UsageException('e2e is auto, require or off (not '.json_encode($mode).')', ['kind' => 'usage']);
        }
        foreach ($pins as $desk => $key) {
            $k = Crypto::deskKey($key);
            if (null === $k) {
                throw new UsageException('e2eKeys['.json_encode((string) $desk).'] is not a 32-byte base64url X25519 key', ['kind' => 'usage']);
            }
            $this->pins[(string) $desk] = $k;
        }
        $this->api = $api(...);
        $this->warn = null !== $warn ? $warn(...) : static function (string $m): void {
            error_log($m);
        };
    }

    /** Forget what the lookup said about a desk (its key may have rotated). */
    public function forget(string $desk): void
    {
        unset($this->cache[$desk]);
    }

    /**
     * @param Call $c
     *
     * @return KeyInfo
     */
    private function info(string $desk, array $c, bool $fresh): array
    {
        $hit = $this->cache[$desk] ?? null;
        if (!$fresh && null !== $hit && microtime(true) - $hit['at'] < (null !== $hit['info']['pub'] ? self::KEY_TTL : self::NO_KEY_TTL)) {
            return $hit['info'];
        }
        try {
            $d = ($this->api)('GET', '/desks/'.rawurlencode($desk), ['deskToken' => $c['deskToken'] ?? null]);
        } catch (GaiaDeskException $e) {
            if ('interrupted' === $e->getKind()) {
                throw $e;
            }

            return ['pub' => null, 'required' => false, 'why' => "its key could not be read (GET /desks/$desk: {$e->getMessage()})"];
        }
        $o = \is_array($d) ? $d : [];
        $pub = Crypto::deskKey($o['e2e_pub'] ?? null);
        $required = true === ($o['e2e_required'] ?? null);
        $why = null !== $pub ? '' : (false === ($o['online'] ?? null) ? 'it is offline, and lists its key only while online' : 'it lists no end-to-end key (a GaiaDesk from before end-to-end encryption?)');
        $info = ['pub' => $pub, 'required' => $required, 'why' => $why];
        $this->cache[$desk] = ['at' => microtime(true), 'info' => $info];

        return $info;
    }

    /**
     * The server's key for a desk, refused when a pinned key differs.
     *
     * @param KeyInfo $info
     */
    private function checked(string $desk, array $info): ?string
    {
        $pin = $this->pins[$desk] ?? null;
        if (null !== $info['pub'] && null !== $pin && !hash_equals($pin, $info['pub'])) {
            $this->forget($desk);
            throw new E2eException("the GaiaDesk API lists a different end-to-end key for desk $desk than the pinned one (e2eKeys); nothing was sent", ['kind' => 'refused', 'reason' => 'e2e_key_mismatch', 'desk' => $desk, 'exitCode' => 254]);
        }

        return $info['pub'] ?? $pin;
    }

    /**
     * The key to seal a desk's next operation to, or null to send it in the clear (auto,
     * no key: warned once). `$insist`: it must be sealed (the API said `e2e_required`).
     * A desk that must be sealed to and lists no key is woken and asked again; still none
     * is an E2eException.
     *
     * @param Call $c
     */
    public function key(string $desk, array $c, bool $insist = false): ?string
    {
        $info = $this->info($desk, $c, $insist);
        $pub = $this->checked($desk, $info);
        if (null !== $pub) {
            return $pub;
        }
        if ('require' !== $this->mode && !$info['required'] && !$insist) {
            $id = $this->baseUrl.' '.$desk;
            if (!isset(self::$warned[$id])) {
                self::$warned[$id] = true;
                ($this->warn)("GaiaDesk: operations on desk $desk are not end-to-end encrypted: {$info['why']}. The API relays them in the clear (pass e2e: 'require' to refuse that).");
            }

            return null;
        }
        try {
            ($this->api)('POST', '/desks/'.rawurlencode($desk).'/wake', ['deskToken' => $c['deskToken'] ?? null, 'json' => ['wait_s' => min(90, $c['wake'] ?? self::DEFAULT_WAKE_S)]]);
        } catch (GaiaDeskException $e) {
            if ('interrupted' === $e->getKind()) {
                throw $e;
            }
        }
        $info = $this->info($desk, $c, true);
        $woke = $this->checked($desk, $info);
        if (null !== $woke) {
            return $woke;
        }
        throw new E2eException("desk $desk must be reached end-to-end encrypted, but {$info['why']}; nothing was sent", ['kind' => 'refused', 'reason' => 'e2e_unavailable', 'desk' => $desk, 'exitCode' => 254]);
    }

    /**
     * Run one desk operation: `$attempt` sends it (sealed, or in the clear when given
     * null). A plaintext call the API refuses `e2e_required` is sealed and sent again; a
     * sealed one the desk could not open (`e2e_decrypt_failed`: its key rotated) is sealed
     * to the key asked for again, once.
     *
     * @template T
     *
     * @param array<string, mixed>    $request
     * @param Call                    $c
     * @param callable(?Sealed): T    $attempt
     *
     * @return T
     */
    public function call(string $desk, string $op, array $request, array $c, callable $attempt): mixed
    {
        if ('off' === $this->mode) {
            return $attempt(null);
        }
        $seal = static fn (string $pub): array => Crypto::sealRequest($pub, $desk, $op, $request);
        $pub = $this->key($desk, $c);
        try {
            return $attempt(null !== $pub ? $seal($pub) : null);
        } catch (RefusedException $e) {
            if (null === $pub && 'e2e_required' === $e->getReason()) {
                $this->forget($desk);
                $again = $this->key($desk, $c, true);

                return $attempt(null !== $again ? $seal($again) : null);
            }
            if (null !== $pub && !$e instanceof E2eException && 'e2e_decrypt_failed' === $e->getReason()) {
                $this->forget($desk);
                $again = $this->key($desk, $c);
                if (null === $again) {
                    throw $e;
                }

                return $attempt($seal($again));
            }
            throw $e;
        }
    }

    /** @internal tests: forget which desks were warned about. */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }
}
