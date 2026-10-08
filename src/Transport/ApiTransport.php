<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use GaiaDesk\E2e\Answers;
use GaiaDesk\E2e\CallerSeal;
use GaiaDesk\E2e\Crypto;
use GaiaDesk\E2e\E2eLayer;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\TransportClient;
use GaiaDesk\Internal\Errors;
use GaiaDesk\Internal\Json;
use GaiaDesk\Stream\SseEvent;
use GaiaDesk\Stream\SseParser;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * GaiaDesk's /v1 HTTP API, wherever it is served: hosted (`api`), on the desk itself
 * (`local`: its Unix socket or named pipe) or a desk's LAN gateway (`lan`). Builds the
 * requests, seals desk operations end to end (hosted only), retries what is safe to
 * retry, and turns every failure into the typed exception of its error envelope.
 *
 * @internal used by {@see GaiaDesk}
 *
 * @phpstan-import-type Sealed from E2eLayer
 */
final class ApiTransport
{
    /** The query parameters a sealed request carries inside instead. */
    private const SEALED_QUERY = ['path', 'tail', 'timeout'];
    private const LABEL = ['api' => 'API', 'local' => 'local', 'lan' => 'lan'];

    /** @var \Closure(?string): array<string, string> */
    private readonly \Closure $credentials;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param 'api'|'local'|'lan'                     $transport
     * @param callable(?string): array<string, string> $credentials the credential headers for one request (the call's desk token, when given)
     * @param callable(float): void|null              $sleep
     */
    public function __construct(
        public readonly string $transport,
        public readonly string $baseUrl,
        private readonly string $where,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        callable $credentials,
        private readonly ?E2eLayer $e2e = null,
        private readonly int $maxRetries = 2,
        private readonly float $connectTimeout = 30.0,
        private readonly ?float $timeout = null,
        ?callable $sleep = null,
    ) {
        $this->credentials = $credentials(...);
        $this->sleep = null !== $sleep ? $sleep(...) : static function (float $s): void {
            usleep((int) ($s * 1e6));
        };
    }

    /** The UsageException for an operation this transport does not serve. */
    public function notServed(string $what, ?string $hint = null): UsageException
    {
        $hint ??= 'api' === $this->transport ? 'use gaiadesk-cli or another GaiaDesk SDK\'s CLI or native transport' : 'use the hosted API (an API key)';

        return new UsageException("$what is not available over the ".self::LABEL[$this->transport]." transport; $hint", ['kind' => 'usage', 'argv' => [$what]]);
    }

    public function hasE2e(): bool
    {
        return null !== $this->e2e;
    }

    // ───────────────────────────── requests ─────────────────────────────

    /**
     * One request, retried as is safe; an HTTP failure is the typed exception from its
     * envelope. A desk operation (`$c->e2e`) on the hosted API is sealed end to end when
     * the desk can open it (each retry sealed afresh).
     *
     * @return array{ResponseInterface, ?CallerSeal}
     */
    public function request(Call $c): array
    {
        for ($attempt = 0;; ++$attempt) {
            try {
                if (null === $this->e2e || null === $c->e2e) {
                    return $this->send($c, null);
                }
                $e2e = $c->e2e;

                return $this->e2e->call($e2e['desk'], $e2e['op'], $e2e['request'], ['deskToken' => $c->deskToken, 'wake' => $c->wake], fn (?array $sealed): array => $this->send($c, $sealed));
            } catch (GaiaDeskException $e) {
                $delay = $this->retryDelay($e, $c, $attempt);
                if (null === $delay) {
                    throw $e;
                }
                ($this->sleep)($delay);
            }
        }
    }

    /** How long to wait before trying again, or null not to. */
    private function retryDelay(GaiaDeskException $e, Call $c, int $attempt): ?float
    {
        if (!$c->retry || $attempt >= $this->maxRetries) {
            return null;
        }
        $backoff = min(8.0, 0.5 * (2 ** $attempt)) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
        $status = $e->getStatus();
        if (429 === $status) {
            // Over the rate limit, or the desk runs 16 operations: nothing ran. Wait as told.
            $after = $e->getRetryAfter() ?? $backoff;

            return $after <= 60.0 ? max(0.0, $after) : null;
        }
        if ('idempotency_key_in_flight' === $e->getReason()) {
            return $backoff;
        }
        $read = 'GET' === $c->method;
        if (null === $status && 'network' === $e->getKind()) {
            $prev = $e->getPrevious();
            $neverSent = $prev instanceof NetworkException && $prev->connectFailed;

            return $neverSent || $read || null !== $c->idempotencyKey ? $backoff : null;
        }
        if ($read && !$c->stream && (502 === $status || 504 === $status)) {
            return $backoff;
        }

        return null;
    }

    /**
     * @param Sealed|null $sealed
     *
     * @return array{ResponseInterface, ?CallerSeal}
     */
    private function send(Call $c, ?array $sealed): array
    {
        $op = $c->op();
        $query = $c->query;
        if (null !== $c->wake) {
            if ($c->wake < 0 || $c->wake > 120) {
                throw new UsageException('wake is whole seconds, 0 to 120', ['kind' => 'usage', 'argv' => [$op]]);
            }
            $query['wake_s'] = $c->wake;
        }
        $headers = ($this->credentials)($c->deskToken);
        $headers['Accept'] = $c->accept;
        $headers['User-Agent'] = 'gaiadesk-php/'.GaiaDesk::VERSION.' (PHP '.\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION.')';
        if (null !== $c->idempotencyKey) {
            $headers['Idempotency-Key'] = $c->idempotencyKey;
        }
        $body = null;
        if (null !== $sealed) {
            // The sealed request carries what the query would have (path, tail, timeout); POST bodies become {"e2e": …}.
            foreach (self::SEALED_QUERY as $k) {
                unset($query[$k]);
            }
            if ('POST' === $c->method) {
                $headers['Content-Type'] = 'application/json';
                $body = $this->streams->createStream(Json::encode(['e2e' => $sealed['request']]));
            } else {
                $headers[Crypto::HEADER] = Crypto::requestHeader($sealed['request']);
                if (null !== $c->bytes) {
                    $headers['Content-Type'] = Crypto::FRAMES_CONTENT_TYPE;
                    $src = \is_string($c->bytes) ? $this->streams->createStream($c->bytes) : $c->bytes;
                    $body = $this->streams->createStreamFromResource(Answers::sealUpload($sealed['seal'], $src, $c->size ?? \strlen((string) $c->bytes)));
                }
            }
        } elseif (null !== $c->json) {
            $headers['Content-Type'] = 'application/json';
            $body = $this->streams->createStream(Json::encode($c->json));
        } elseif (null !== $c->bytes) {
            $headers['Content-Type'] = 'application/octet-stream';
            $body = \is_string($c->bytes) ? $this->streams->createStream($c->bytes) : $c->bytes;
            if ($body->isSeekable()) {
                $body->rewind();
            }
        }
        $req = $this->requests->createRequest($c->method, $this->url($c->path, $query));
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if (null !== $body) {
            $req = $req->withBody($body);
        }
        $opts = new RequestOptions($c->timeout ?? ($c->stream ? null : $this->timeout), $c->idleTimeout, $this->connectTimeout, $c->stream);
        try {
            $res = $this->client instanceof TransportClient ? $this->client->sendWith($req, $opts) : $this->client->sendRequest($req);
        } catch (GaiaDeskException $e) {
            // The transport's own typed error (local: no socket; lan: the fingerprint did not match).
            throw [] === $e->getArgv() ? $e->with(['argv' => [$op]]) : $e;
        } catch (ClientExceptionInterface $e) {
            $timedOut = $e instanceof NetworkException && $e->timedOut;
            throw new UnreachableException("{$this->where} could not be reached: {$e->getMessage()}", ['kind' => $timedOut ? 'timeout' : 'network', 'reason' => $timedOut ? 'timeout' : 'network', 'exitCode' => 255, 'argv' => [$op]], $e);
        }
        $status = $res->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $seal = $sealed['seal'] ?? null;
            throw self::apiError($res, $op, null !== $seal ? static fn (mixed $json): mixed => Answers::openErrorEnvelope($json, $seal, [$op]) : null);
        }

        return [$res, $sealed['seal'] ?? null];
    }

    /**
     * @param array<string, string|int|null> $query
     */
    private function url(string $path, array $query): string
    {
        $q = [];
        foreach ($query as $k => $v) {
            if (null !== $v) {
                $q[] = rawurlencode($k).'='.rawurlencode((string) $v);
            }
        }

        return $this->baseUrl.$path.([] !== $q ? '?'.implode('&', $q) : '');
    }

    /** A request answered with JSON (a sealed answer opened). */
    public function json(Call $c): mixed
    {
        [$res, $seal] = $this->request($c);
        $text = (string) $res->getBody();
        $json = Json::decode($text, $sentinel = new \stdClass());
        if ($json === $sentinel) {
            throw new ProtocolException("the GaiaDesk API answered {$c->op()} with something that is not JSON", ['kind' => 'protocol', 'argv' => [$c->op()], 'status' => $res->getStatusCode(), 'requestId' => $res->getHeaderLine('X-Request-Id') ?: null, 'body' => substr($text, 0, 4096)]);
        }

        return null !== $seal ? Answers::openAnswer($json, $seal, [$c->op()]) : $json;
    }

    /**
     * A request answered with Server-Sent Events: its events, JSON-decoded (each with
     * its `event`; a sealed stream opened into the plaintext events), and how to hang up.
     *
     * @param 'exec'|'logs' $kind
     *
     * @return array{events: \Generator<int, array<string, mixed>>, close: \Closure(): void}
     */
    public function events(Call $c, string $kind): array
    {
        [$res, $seal] = $this->request($c);
        $body = $res->getBody();
        $events = self::sseObjects($body);
        if (null !== $seal) {
            $events = Answers::unseal($events, $seal, $kind, [$c->op()]);
        }

        return ['events' => $events, 'close' => static function () use ($body): void {
            $body->close();
        }];
    }

    /**
     * The JSON objects of an event stream, as they arrive (each named by its SSE event
     * when its JSON has no `event`).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function sseObjects(StreamInterface $body): \Generator
    {
        $p = new SseParser();
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' === $chunk) {
                continue;
            }
            yield from self::objectsOf($p->feed($chunk));
        }
        yield from self::objectsOf($p->end());
    }

    /**
     * @param list<SseEvent> $events
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function objectsOf(array $events): \Generator
    {
        foreach ($events as $ev) {
            $v = Json::decode($ev->data);
            if (!Json::isObject($v) || !\is_array($v)) {
                continue;
            }
            if (!\is_string($v['event'] ?? null)) {
                $v['event'] = $ev->event;
            }
            /** @var array<string, mixed> $v */
            yield $v;
        }
    }

    /**
     * The typed exception for a failed request: its error envelope, else a ProtocolException.
     *
     * @param (callable(mixed): mixed)|null $open opens a sealed operation's desk error
     */
    public static function apiError(ResponseInterface $res, string $op, ?callable $open = null): GaiaDeskException
    {
        try {
            $text = (string) $res->getBody();
        } catch (\Throwable) {
            $text = '';
        }
        $json = Json::decode($text);
        if (null !== $open && null !== $json) {
            $json = $open($json);
        }
        $headerId = $res->getHeaderLine('X-Request-Id');
        $ra = $res->getHeaderLine('Retry-After');
        $retryAfter = '' !== $ra && is_numeric($ra) ? (float) $ra : null;
        $status = $res->getStatusCode();
        $env = Errors::envelope($json);
        if (null === $env) {
            return new ProtocolException("the GaiaDesk API answered $op with HTTP $status and no error envelope", [
                'kind' => 'protocol', 'exitCode' => 255, 'argv' => [$op], 'json' => $json, 'body' => substr($text, 0, 4096),
                'status' => $status, 'requestId' => '' !== $headerId ? $headerId : null, 'retryAfter' => $retryAfter,
            ]);
        }
        /** @var array<string, mixed> $error */
        $error = \is_array($json) && \is_array($json['error'] ?? null) ? $json['error'] : [];
        $requestId = \is_string($error['request_id'] ?? null) ? $error['request_id'] : ('' !== $headerId ? $headerId : null);
        $details = Errors::envelopeDetails($env, ['exitCode' => Errors::deskOpExit($env['kind']), 'argv' => [$op], 'json' => $json, 'status' => $status, 'requestId' => $requestId, 'retryAfter' => $retryAfter]);

        return Errors::forKind($env['kind'], '' !== $env['message'] ? $env['message'] : "HTTP $status", $details);
    }
}
