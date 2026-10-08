<?php

declare(strict_types=1);

namespace GaiaDesk;

use GaiaDesk\E2e\E2eLayer;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\Http\CurlClient;
use GaiaDesk\Internal\Args;
use GaiaDesk\Internal\Json;
use GaiaDesk\Transport\ApiTransport;
use GaiaDesk\Transport\Call;
use GaiaDesk\Transport\Lan;
use GaiaDesk\Transport\Local;
use GaiaDesk\Transport\Retry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The GaiaDesk client: drive desks through GaiaDesk's /v1 API.
 *
 * ```php
 * $gd = new GaiaDesk\GaiaDesk(apiKey: getenv('GAIADESK_API_KEY'), deskToken: getenv('GAIADESK_DESK_TOKEN'));
 * $r = $gd->exec('123456789', 'uname -a');
 * echo $r['stdout'];
 * ```
 *
 * Three transports speak the same API, with the same methods, results and exceptions:
 *
 * - `api` (default): the hosted API, `https://api.gaiadesk.net/v1`, with an API key
 *   (`ak_…`) and, for desk operations, a scoped agent token (`gdagt_…`) the desk checks.
 *   Desk operations are end-to-end encrypted to the desk's key when it publishes one.
 * - `local` ({@see self::local()}): code running ON a desk, through the GaiaDesk app's own
 *   API on its Unix socket or Windows named pipe, with the desk's local admin token or an
 *   agent token.
 * - `lan` ({@see self::lan()}): a desk's opt-in LAN gateway over TLS, its certificate
 *   pinned by fingerprint, with an agent token.
 *
 * Results are the API's JSON objects as PHP arrays (their shapes: {@see Types}); failures
 * are typed exceptions ({@see Exception\GaiaDeskException} and its subclasses).
 *
 * @phpstan-import-type ApiDeskList from Types
 */
final class GaiaDesk
{
    use Concerns\Commands;
    use Concerns\Files;
    use Concerns\Fleet;
    use Concerns\Jobs;
    use Concerns\Tokens;

    /** This SDK's version. */
    public const VERSION = '0.1.2';
    /** The default {@see __construct()} `responseTimeout`: 16 minutes, above the API's 15-minute limit on a call. */
    public const DEFAULT_RESPONSE_TIMEOUT = 960.0;
    /** The default {@see __construct()} `idleTimeout`: the API's streams and held waits send a keep-alive every 15 s. */
    public const DEFAULT_IDLE_TIMEOUT = 90.0;
    public const DEFAULT_API_URL = 'https://api.gaiadesk.net/v1';
    /** The most one file may be through the API (256 MB). */
    public const API_FILE_LIMIT = 268435456;
    /** The longest one `GET …/jobs/{name}/wait` is held, in seconds (the API's `timeout` maximum). */
    public const API_WAIT_MAX = 870;
    /** The scopes an agent token minted over the API may carry ({@see createToken()}). */
    public const TOKEN_SCOPES = ['exec', 'shell', 'cp', 'forward', 'jobs', 'screen'];
    /** The events a webhook may subscribe to ({@see createWebhook()}). */
    public const WEBHOOK_EVENTS = ['desk.online', 'desk.offline', 'desk.woke', 'job.finished', 'support.session.joined', 'support.session.ended'];

    private readonly ApiTransport $t;

    /**
     * @param string|null                  $apiKey          an Atlas API key (`ak_…`) or a signed-in person's session/OAuth token (`api` transport)
     * @param string|null                  $deskToken       a scoped agent token (`gdagt_…`) sent with every desk operation (a call's `deskToken:` overrides it)
     * @param string|null                  $baseUrl         the API's base URL (`…/v1`): default {@see DEFAULT_API_URL}; for `lan`, the gateway's `https://<desk>:7443/v1`
     * @param string                       $transport       which API: `api` (the hosted one, default), `local` (the desk's own) or `lan` (a desk's LAN gateway)
     * @param string|null                  $socketPath      `local`: the socket or pipe (default: `$GAIADESK_API_DIR/api.sock` or `~/.gaiadesk/api.sock`; on Windows `\\.\pipe\gaiadesk-api-<user>`)
     * @param string|null                  $token           `local`: the desk's local admin token (default: read from `~/.gaiadesk/api-token` on each request)
     * @param string|null                  $fingerprint     `lan`: the gateway certificate's SHA-256 fingerprint, as the desk shows it
     * @param string                       $e2e             end-to-end encryption of desk operations (`api` only): `auto` seals when the desk lists a key; `require` never sends in the clear; `off` never seals
     * @param array<array-key, string>     $e2eKeys         pinned desk keys, `[deskId => e2e_pub]`: a different key from the server is refused
     * @param callable(string): void|null  $onWarning       where the SDK's warnings go (default: error_log)
     * @param ClientInterface|null         $httpClient      a PSR-18 client to send requests with (default: the SDK's curl client, which streams); `api` transport only
     * @param float                        $connectTimeout  seconds a connection may take
     * @param float|null                   $timeout         seconds any non-streamed request may take (default: per operation, from its own limits)
     * @param int                          $maxRetries      how many times a request that is safe to retry is sent again (default 2: 3 attempts in all; 0: never). See the README's Retries
     * @param array<string, string>|null   $env             `local`: the environment the defaults are read from (default: this process's)
     * @param callable(float): void|null   $sleep           how to wait between retries (default: usleep; for tests)
     * @param float|null                   $responseTimeout seconds an answer may take to begin (its status and headers), sending the request
     *                                                      included (default 16 minutes; null: no limit). Exceeded: UnreachableException, kind `timeout`
     * @param float|null                   $idleTimeout     seconds any read of an answer's body (JSON, a download, an event stream) may wait for a
     *                                                      byte (default 90; null: no limit). Exceeded: ConnectionLostException, kind `timeout`
     * @param float                        $retryBaseDelay  seconds of the first backoff wait between retries, doubling each time (default 0.25), times a random 0.5 to 1.0
     * @param float                        $retryMaxDelay   the longest backoff wait, in seconds (default 8)
     * @param float                        $maxRetryWait    the longest `Retry-After` (429, 503) waited for, in seconds (default 60): a longer one is not waited for, the error carries it
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $deskToken = null,
        ?string $baseUrl = null,
        string $transport = 'api',
        ?string $socketPath = null,
        ?string $token = null,
        ?string $fingerprint = null,
        string $e2e = 'auto',
        array $e2eKeys = [],
        ?callable $onWarning = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        float $connectTimeout = 30.0,
        ?float $timeout = null,
        int $maxRetries = 2,
        ?array $env = null,
        ?callable $sleep = null,
        ?float $responseTimeout = self::DEFAULT_RESPONSE_TIMEOUT,
        ?float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        float $retryBaseDelay = Retry::BASE_DELAY,
        float $retryMaxDelay = Retry::MAX_DELAY,
        float $maxRetryWait = Retry::MAX_RETRY_WAIT,
    ) {
        $factory = new Psr17Factory();
        $responseTimeout = Args::timeLimit($responseTimeout, 'responseTimeout');
        $idleTimeout = Args::timeLimit($idleTimeout, 'idleTimeout');
        $requests = $requestFactory ?? $factory;
        $streams = $streamFactory ?? $factory;
        $deskToken = Args::credential($deskToken, 'deskToken (a scoped agent token, gdagt_…)');
        if ($maxRetries < 0) {
            throw Args::usage('maxRetries must be 0 or more');
        }
        $retry = new Retry($maxRetries, Args::delay($retryBaseDelay, 'retryBaseDelay'), Args::delay($retryMaxDelay, 'retryMaxDelay'), Args::delay($maxRetryWait, 'maxRetryWait'));
        $common = [$requests, $streams];
        if ('api' === $transport) {
            foreach (['socketPath' => $socketPath, 'token' => $token, 'fingerprint' => $fingerprint] as $k => $v) {
                if (null !== $v) {
                    throw Args::usage("$k is an option of the local or lan transport, not the api transport");
                }
            }
            $key = Args::credential($apiKey, 'apiKey');
            if (null === $key) {
                throw Args::usage('the api transport needs an apiKey (an Atlas API key, ak_…, or a person\'s session token)');
            }
            $url = rtrim($baseUrl ?? self::DEFAULT_API_URL, '/');
            if (1 !== preg_match('#^https?://[^/]#i', $url)) {
                throw Args::usage('baseUrl must be an http(s) URL: '.Json::encode($baseUrl));
            }
            $credentials = static function (?string $callToken) use ($key, $deskToken): array {
                $h = ['Authorization' => 'Bearer '.$key];
                $t = $callToken ?? $deskToken;
                if (null !== $t) {
                    $h['X-GaiaDesk-Desk-Token'] = $t;
                }

                return $h;
            };
            $t = null;
            $layer = new E2eLayer($e2e, $e2eKeys, static function (string $method, string $path, array $r) use (&$t): mixed {
                /** @var ApiTransport $t */
                return $t->json(new Call($method, $path, json: $r['json'] ?? null, deskToken: $r['deskToken'] ?? null, timeout: 120.0, retry: false));
            }, $url, $onWarning);
            $t = new ApiTransport('api', $url, "the GaiaDesk API ($url)", $httpClient ?? new CurlClient(), ...$common, credentials: $credentials, e2e: $layer, retry: $retry, connectTimeout: $connectTimeout, timeout: $timeout, sleep: $sleep, responseTimeout: $responseTimeout, idleTimeout: $idleTimeout);
            $this->t = $t;

            return;
        }
        if (null !== $apiKey) {
            throw Args::usage("apiKey is the api transport's; the $transport transport uses ".('local' === $transport ? 'the desk\'s local admin token or deskToken' : 'deskToken'));
        }
        if ('auto' !== $e2e || [] !== $e2eKeys) {
            throw Args::usage('end-to-end encryption (e2e, e2eKeys) is the hosted api transport\'s: local and lan never leave the desk or the LAN');
        }
        if (null !== $httpClient) {
            throw Args::usage("httpClient is the api transport's: the $transport transport opens its own connections");
        }
        if ('local' === $transport) {
            if (null !== $fingerprint || null !== $baseUrl) {
                throw Args::usage('fingerprint and baseUrl are not options of the local transport');
            }
            $env ??= Local::processEnv();
            $windows = \PHP_OS_FAMILY === 'Windows';
            $home = Local::home($env, $windows);
            $where = Args::credential($socketPath, 'socketPath') ?? ($windows ? Local::pipeName($env, (string) get_current_user()) : Local::socketPath($env, $home, false));
            $adminToken = Args::credential($token, 'token');
            $tokenFile = Local::tokenPath($env, $home, $windows);
            $credentials = static function (?string $callToken) use ($deskToken, $adminToken, $tokenFile): array {
                $t = $callToken ?? $deskToken;
                if (null !== $t) {
                    return ['X-GaiaDesk-Desk-Token' => $t];
                }

                return ['Authorization' => 'Bearer '.($adminToken ?? Local::adminToken($tokenFile))];
            };
            $this->t = new ApiTransport('local', 'http://localhost/v1', "the desk's local API ($where)", Local::client($where, $windows), ...$common, credentials: $credentials, retry: $retry, connectTimeout: $connectTimeout, timeout: $timeout, sleep: $sleep, responseTimeout: $responseTimeout, idleTimeout: $idleTimeout);

            return;
        }
        if ('lan' === $transport) {
            if (null !== $socketPath || null !== $token) {
                throw Args::usage('socketPath and token are not options of the lan transport');
            }
            if (null === $baseUrl || 1 !== preg_match('#^https://[^/]#i', $baseUrl)) {
                throw Args::usage('the lan transport needs an https:// baseUrl (https://<desk>:7443/v1), not '.Json::encode($baseUrl));
            }
            if (null === $fingerprint) {
                throw Args::usage("the lan transport needs the gateway certificate's fingerprint (Settings → GaiaDesk API → LAN gateway)");
            }
            $pinned = Lan::normalizeFingerprint($fingerprint);
            $url = rtrim($baseUrl, '/');
            $origin = (string) parse_url($url, \PHP_URL_HOST).':'.(parse_url($url, \PHP_URL_PORT) ?? 443);
            $credentials = static function (?string $callToken) use ($deskToken): array {
                $t = $callToken ?? $deskToken;
                if (null === $t) {
                    throw new UsageException("the lan transport needs an agent token (deskToken, gdagt_…): a desk's LAN gateway does not take its admin token", ['kind' => 'usage']);
                }

                return ['X-GaiaDesk-Desk-Token' => $t];
            };
            $this->t = new ApiTransport('lan', $url, "the desk's LAN gateway ($origin)", Lan::client($url, $pinned), ...$common, credentials: $credentials, retry: $retry, connectTimeout: $connectTimeout, timeout: $timeout, sleep: $sleep, responseTimeout: $responseTimeout, idleTimeout: $idleTimeout);

            return;
        }
        throw Args::usage('transport is api, local or lan (not '.Json::encode($transport).')');
    }

    /**
     * The `local` transport: code running on a desk, through the GaiaDesk app's own API
     * (its Unix socket or Windows named pipe).
     *
     * @param string|null                $socketPath the socket or pipe (default: as the GaiaDesk app places it)
     * @param string|null                $token      the desk's local admin token (default: read from its file)
     * @param string|null                $deskToken  an agent token (`gdagt_…`), sent instead of the admin token: its scopes apply
     * @param array<string, string>|null $env
     */
    public static function local(?string $socketPath = null, ?string $token = null, ?string $deskToken = null, ?array $env = null, int $maxRetries = 2, ?float $timeout = null, ?float $responseTimeout = self::DEFAULT_RESPONSE_TIMEOUT, ?float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT, float $retryBaseDelay = Retry::BASE_DELAY, float $retryMaxDelay = Retry::MAX_DELAY, float $maxRetryWait = Retry::MAX_RETRY_WAIT): self
    {
        return new self(deskToken: $deskToken, transport: 'local', socketPath: $socketPath, token: $token, env: $env, maxRetries: $maxRetries, timeout: $timeout, responseTimeout: $responseTimeout, idleTimeout: $idleTimeout, retryBaseDelay: $retryBaseDelay, retryMaxDelay: $retryMaxDelay, maxRetryWait: $maxRetryWait);
    }

    /**
     * The `lan` transport: a desk's LAN gateway, its certificate pinned.
     *
     * @param string      $baseUrl     `https://gaiadesk-<desk id>.local:7443/v1` or a LAN address
     * @param string      $fingerprint the certificate's SHA-256, as the desk's Settings show it (`ab:cd:…`)
     * @param string|null $deskToken   the agent token (`gdagt_…`); required here or on each call
     */
    public static function lan(string $baseUrl, string $fingerprint, ?string $deskToken = null, int $maxRetries = 2, ?float $timeout = null, float $connectTimeout = 10.0, ?float $responseTimeout = self::DEFAULT_RESPONSE_TIMEOUT, ?float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT, float $retryBaseDelay = Retry::BASE_DELAY, float $retryMaxDelay = Retry::MAX_DELAY, float $maxRetryWait = Retry::MAX_RETRY_WAIT): self
    {
        return new self(deskToken: $deskToken, baseUrl: $baseUrl, transport: 'lan', fingerprint: $fingerprint, maxRetries: $maxRetries, timeout: $timeout, connectTimeout: $connectTimeout, responseTimeout: $responseTimeout, idleTimeout: $idleTimeout, retryBaseDelay: $retryBaseDelay, retryMaxDelay: $retryMaxDelay, maxRetryWait: $maxRetryWait);
    }

    /** Which transport this client uses: `api`, `local` or `lan`. */
    public function transport(): string
    {
        return $this->t->transport;
    }

    /** The API's base URL (`…/v1`). */
    public function baseUrl(): string
    {
        return $this->t->baseUrl;
    }

    /**
     * `GET /desks`: the desks on the account and its team (`api`), or this desk (`local`,
     * `lan`): `{devices, sources, notes, identity}`, online desks first. With `$deskId`,
     * that desk's row only.
     *
     * @return ApiDeskList
     */
    public function devices(?string $deskId = null): array
    {
        $r = $this->t->json(new Call('GET', '/desks', timeout: 60.0));
        if (!\is_array($r) || !\is_array($r['devices'] ?? null)) {
            throw new Exception\ProtocolException('the GaiaDesk API listed no devices', ['kind' => 'protocol', 'argv' => ['GET /desks'], 'json' => $r]);
        }
        /** @var ApiDeskList $r */
        if (null === $deskId) {
            return $r;
        }
        $id = Args::desk($deskId);
        $r['devices'] = array_values(array_filter($r['devices'], static fn (array $d): bool => $d['desk_id'] === $id));

        return $r;
    }

    // ───────────────────────────── shared ─────────────────────────────

    /** `/desks/{id}`. */
    private function deskPath(string $deskId): string
    {
        return '/desks/'.rawurlencode(Args::desk($deskId));
    }

    /** The idle limit for a desk operation that may first wait for a sleeping desk. */
    private static function idleFor(?int $wake): float
    {
        return (float) max(120, ($wake ?? 60) + 60);
    }

    /** An Idempotency-Key as the API takes it: 1 to 255 printable ASCII characters. */
    private static function idempotencyKey(?string $key): ?string
    {
        if (null === $key) {
            return null;
        }
        if (1 !== preg_match('/^[\x20-\x7e]{1,255}$/D', $key)) {
            throw Args::usage('idempotencyKey is 1 to 255 printable ASCII characters');
        }

        return $key;
    }

    /**
     * A JSON object answer, or the ProtocolException for anything else.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $json, string $op, string $what): array
    {
        if (!Json::isObject($json) || !\is_array($json)) {
            throw new Exception\ProtocolException("the GaiaDesk API answered $op without $what", ['kind' => 'protocol', 'argv' => [$op], 'json' => $json]);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * The list in a result (`{"jobs": [...]}`, `{"tokens": [...]}`, ...), or the ProtocolException.
     *
     * @return list<mixed>
     */
    private static function listOf(mixed $json, string $key, string $op): array
    {
        if (\is_array($json) && \is_array($json[$key] ?? null) && array_is_list($json[$key])) {
            return $json[$key];
        }
        throw new Exception\ProtocolException("the GaiaDesk API answered $op with no $key list", ['kind' => 'protocol', 'argv' => [$op], 'json' => $json]);
    }
}
