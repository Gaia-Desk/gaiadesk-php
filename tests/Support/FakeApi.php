<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use GaiaDesk\E2e\Crypto;

/**
 * A fake of GaiaDesk's /v1 API AND the desks behind it, in plain PHP: the hosted API's
 * fleet routes (desks, reach, wake, audit, webhooks, support sessions) and every desk
 * operation, answered as the real one answers (signal/src/api_v1): plaintext JSON / SSE /
 * bytes for a plaintext call; `{"e2e": {"events"}}`, the error envelope with a
 * placeholder message and `e2e.events`, `sealed` SSE events and NDJSON downloads for a
 * sealed one. Desks that hold a secret list `e2e_pub` while online and open sealed
 * requests with it. Every request is recorded raw, so a test can prove what the API saw.
 *
 * Desk ids with behaviour of their own:
 *   999999990  answers HTML with a 500 (no envelope)
 *   999999991  rate limited (429, Retry-After: 7)
 *   999999992  busy for the first `busy` requests (429 desk_busy, Retry-After: 0), then fine
 *   999999993  offline (409 unreachable, reason offline)
 *   999999994  a 400 usage error
 *   999999995  times out (504 unreachable, reason timeout)
 *   999999996  502 for the first `flaky` GETs, then fine
 *
 * `mode`: `api` (hosted: `Authorization: Bearer` required; a key `ak_…` needs a desk token
 * for desk operations and may not administer tokens), `local` (the desk's own: the admin
 * token `gdlocal_…` or an agent token), `lan` (agent tokens only).
 */
final class FakeApi
{
    public const HTML = '999999990';
    public const LIMITED = '999999991';
    public const BUSY = '999999992';
    public const OFFLINE = '999999993';
    public const USAGE = '999999994';
    public const TIMEOUT = '999999995';
    public const FLAKY = '999999996';

    /** @var list<Recorded> */
    public array $requests = [];
    /** @var list<string> sealed operations run */
    public array $sealed = [];
    /** @var list<string> plaintext operations run */
    public array $plain = [];
    /** @var list<string> */
    public array $wakes = [];
    /** Misbehave as a hostile server: flip a bit of each sealed event, or answer a sealed call in the clear. */
    public ?string $tamper = null;
    /** @var array<string, string> */
    public array $files = [];
    public int $busy = 0;
    public int $flaky = 0;
    /** @var array<string, array<string, mixed>> */
    public array $webhooks = [];
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];
    /** @var list<array<string, mixed>> */
    public array $audit = [];
    private int $rid = 0;
    private int $seq = 0;

    /**
     * @param array<array-key, array<string, mixed>> $desks
     */
    public function __construct(public array $desks = [], public string $mode = 'api')
    {
        foreach (['123456789', self::HTML, self::LIMITED, self::BUSY, self::OFFLINE, self::USAGE, self::TIMEOUT, self::FLAKY] as $d) {
            if (!isset($this->desks[$d])) {
                $this->desks[$d] = [];
            }
        }
        for ($i = 0; $i < 250; ++$i) {
            // 250 audit events, newest first, two per millisecond.
            $this->audit[] = ['id' => \sprintf('ae_%04d', $i), 'action' => 0 === $i % 3 ? 'api.execOnDesk' : 'desk.session.start', 'stream' => 0 === $i % 3 ? 'api' : 'session', 'occurred_at_ms' => 1791300000000 - intdiv($i, 2), 'actor' => ['type' => 'user', 'id' => 'you@example.com'], 'target' => ['type' => 'desk', 'id' => '123456789', 'name' => null], 'metadata' => new \stdClass()];
        }
    }

    public function last(): Recorded
    {
        return $this->requests[\count($this->requests) - 1];
    }

    private function requestId(): string
    {
        return \sprintf('req_%024x', ++$this->rid);
    }

    /**
     * @param array<array-key, mixed>|object $v
     * @param array<string, string>          $headers
     */
    private function json(int $status, array|object $v, array $headers = []): FakeResponse
    {
        return new FakeResponse($status, ['Content-Type' => 'application/json', 'X-Request-Id' => $this->requestId()] + $headers, [(string) json_encode($v, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array{error: array<string, mixed>} */
    private function envelope(string $kind, string $message, ?string $reason, ?string $desk = null): array
    {
        $e = ['kind' => $kind, 'message' => $message, 'reason' => $reason ?? $kind, 'request_id' => $this->requestId()];
        if (null !== $desk) {
            $e['desk'] = $desk;
        }

        return ['error' => $e];
    }

    /** @param array<string, string> $headers */
    private function error(int $status, string $kind, string $message, ?string $reason, ?string $desk = null, array $headers = []): FakeResponse
    {
        return $this->json($status, $this->envelope($kind, $message, $reason, $desk), $headers);
    }

    public static function statusOf(string $kind, ?string $reason): int
    {
        return match ($kind) {
            'usage' => 400,
            'refused' => 'desk_busy' === $reason ? 429 : ('e2e_required' === $reason ? 409 : 403),
            'unreachable' => 409,
            'connection_lost', 'protocol' => 502,
            default => 422,
        };
    }

    /**
     * Handle one request.
     *
     * @param array<string, string> $headers lowercased names
     */
    public function handle(string $method, string $target, array $headers, string $body): FakeResponse
    {
        $u = parse_url($target);
        $path = rawurldecode($u['path'] ?? '/');
        $query = [];
        parse_str($u['query'] ?? '', $query);
        /** @var array<string, string> $query */
        $rec = new Recorded($method, $u['path'] ?? '/', $query, $headers, $body);
        $this->requests[] = $rec;
        try {
            return $this->route($rec, $path);
        } catch (\Throwable $e) {
            return new FakeResponse(500, ['Content-Type' => 'text/plain'], ['fake API: '.$e->getMessage()."\n".$e->getTraceAsString()]);
        }
    }

    private function route(Recorded $rec, string $path): FakeResponse
    {
        $auth = $rec->header('authorization') ?? '';
        $deskToken = $rec->header('x-gaiadesk-desk-token');
        $key = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if ('api' !== $this->mode) {
            if (str_starts_with($key, 'gdlocal_') && 'lan' === $this->mode) {
                return $this->error(401, 'refused', "the desk's admin token works only on the desk itself; send an agent token in X-GaiaDesk-Desk-Token", 'admin_token_local_only');
            }
            if ('' !== $key && !str_starts_with($key, 'gdlocal_')) {
                return $this->error(401, 'refused', "not this desk's admin token", 'unauthenticated');
            }
            $key = '' !== $key ? 'session-admin' : (null !== $deskToken ? 'ak_desk-token' : '');
        }
        if ('' === $key) {
            return $this->error(401, 'refused', 'Sign in, or send an API key as `Authorization: Bearer ak_…`.', 'unauthenticated');
        }
        $isKey = str_starts_with($key, 'ak_');

        if ('api' === $this->mode) {
            if ('/v1/audit' === $path && 'GET' === $rec->method) {
                return $this->auditRoute($rec);
            }
            if (str_starts_with($path, '/v1/webhooks')) {
                return $this->webhookRoute($rec, $path);
            }
            if (str_starts_with($path, '/v1/support/sessions')) {
                return $this->supportRoute($rec, $path);
            }
        } elseif (1 !== preg_match('#^/v1/desks#', $path) || 1 === preg_match('#^/v1/desks/[^/]+(/reach|/wake)?$#', $path) && !('GET' === $rec->method && '/v1/desks' === $path)) {
            return $this->error(404, 'unreachable', 'not served by the desk', 'no_such_route');
        }
        if ('/v1/desks' === $path && 'GET' === $rec->method) {
            $devices = [];
            foreach ($this->desks as $id => $d) {
                $devices[] = ['desk_id' => (string) $id, 'name' => "desk $id", 'online' => false !== ($d['online'] ?? true), 'owner' => 'you', 'sources' => ['account']];
            }

            return $this->json(200, ['devices' => $devices, 'sources' => ['server'], 'notes' => [], 'identity' => ['account' => 'you@example.com', 'source' => 'api_key']]);
        }
        if (1 !== preg_match('#^/v1/desks/([^/]+)(/.*)?$#', $path, $m)) {
            return $this->error(404, 'unreachable', 'no such route', 'no_such_route');
        }
        $id = $m[1];
        $rest = $m[2] ?? '';
        if (!isset($this->desks[$id])) {
            return $this->error(404, 'unreachable', 'No desk with this id on your account or team.', 'unknown_desk', $id);
        }
        $d = &$this->desks[$id];
        $online = false !== ($d['online'] ?? true);
        if (self::HTML === $id) {
            return new FakeResponse(500, ['Content-Type' => 'text/html'], ['<html><body>Internal Server Error</body></html>']);
        }
        if (self::LIMITED === $id) {
            return $this->error(429, 'refused', 'Too many requests for this key; try again in 7 s.', 'rate_limited', null, ['Retry-After' => '7']);
        }
        if (self::BUSY === $id && $this->busy > 0) {
            --$this->busy;

            return $this->error(429, 'refused', 'The desk already runs 16 API operations.', 'desk_busy', $id, ['Retry-After' => '0']);
        }
        if (self::FLAKY === $id && 'GET' === $rec->method && $this->flaky > 0) {
            --$this->flaky;

            return $this->error(502, 'connection_lost', 'The desk went away during this operation.', 'desk_disconnected', $id);
        }
        if (self::OFFLINE === $id && '' !== $rest) {
            return $this->error(409, 'unreachable', 'desk 999999993 is offline (silent)', 'offline', $id);
        }
        if (self::USAGE === $id && '' !== $rest) {
            return $this->error(400, 'usage', 'bad request', 'bad_body', $id);
        }
        if (self::TIMEOUT === $id && '' !== $rest) {
            return $this->error(504, 'unreachable', 'the desk did not answer in time', 'timeout', $id);
        }
        if ('' === $rest && 'GET' === $rec->method) {
            $stale = ($d['hideKeyLookups'] ?? 0) > 0;
            if ($stale) {
                $d['hideKeyLookups'] = (int) $d['hideKeyLookups'] - 1;
            }
            $o = ['desk_id' => $id, 'name' => "desk $id", 'online' => $online, 'owner' => 'you', 'sources' => ['account'], 'features' => isset($d['secret']) ? ['desk_op', 'desk_op_e2e'] : ['desk_op'], 'e2e_required' => ($d['required'] ?? false) && !$stale, 'wake' => ['doorbell_sockets' => 1, 'lan_wake' => true]];
            $o['e2e_pub'] = $online && isset($d['secret']) && !$stale ? Crypto::b64url(Crypto::x25519Public($d['secret'])) : null;

            return $this->json(200, $o);
        }
        if ('/reach' === $rest && 'GET' === $rec->method) {
            return $this->json(200, ['desk_id' => $id, 'since' => (int) ($rec->query['since'] ?? 1790700000), 'events' => [['at' => 1791290000, 'online' => false, 'reason' => 'silent', 'reason_text' => 'nothing heard from the host'], ['at' => 1791200000, 'online' => true, 'reason' => 'registered', 'reason_text' => 'connected', 'version' => '0.10.325']]]);
        }
        if ('/wake' === $rest && 'POST' === $rec->method) {
            $this->wakes[] = $id;
            $already = $online;
            if ($d['wakeable'] ?? false) {
                $d['online'] = true;
            }

            return $this->json(200, ['desk_id' => $id, 'online' => false !== ($d['online'] ?? true), 'woke' => !$already && ($d['wakeable'] ?? false), 'already_online' => $already, 'rang' => ['doorbell' => $already ? 0 : 1, 'lan_helpers' => 0], 'waited_ms' => 5]);
        }

        return $this->deskOp($rec, $id, $rest, $d, $isKey, $deskToken);
    }

    /**
     * @param array<string, mixed> $d
     */
    private function deskOp(Recorded $rec, string $id, string $rest, array &$d, bool $isKey, ?string $deskToken): FakeResponse
    {
        $tokens = str_starts_with($rest, '/tokens');
        if ($tokens && $isKey) {
            return $this->error(403, 'refused', "token administration over the API works only for a signed-in person's own desk", 'session_required', $id);
        }
        if (!$tokens && $isKey && null === $deskToken) {
            return $this->error(403, 'refused', 'from an API key, desk operations need a scoped agent token in X-GaiaDesk-Desk-Token', 'desk_token_required', $id);
        }
        // A sealed request: the POST body's `e2e`, or the header.
        $sealedReq = null;
        if ('POST' === $rec->method && '' !== $rec->body) {
            $j = json_decode($rec->body, true);
            if (\is_array($j) && isset($j['e2e'])) {
                $sealedReq = $j['e2e'];
            }
        }
        $h = $rec->header('gaiadesk-e2e');
        if (null !== $h) {
            $sealedReq = json_decode((string) Crypto::b64decode($h), true);
        }
        $r = $this->routeOp($rec->method, $rest, $rec->query, null !== $sealedReq ? '' : $rec->body);
        if (null === $r) {
            return $this->error(400, 'usage', 'no route', 'no_route');
        }
        $online = false !== ($d['online'] ?? true);
        if (!$online && null === $sealedReq) {
            $d['online'] = true; // the API wakes it for the operation
        }
        $op = $r['req'];
        $seal = null;
        $input = $rec->body;
        if (null !== $sealedReq) {
            if (!isset($d['secret'])) {
                return $this->error(409, 'protocol', 'the desk cannot open end-to-end encrypted operations', 'e2e_unsupported', $id);
            }
            $opened = null;
            foreach (array_merge([$d['secret']], $d['previous'] ?? []) as $k) {
                try {
                    $opened = DeskSeal::openRequest($k, $id, $r['op'], (array) $sealedReq);
                    break;
                } catch (\Throwable) {
                }
            }
            if (null === $opened) {
                return $this->error(403, 'refused', "the end-to-end encrypted request did not open: it was altered, or sealed to another key (fetch the desk's e2e_pub again)", 'e2e_decrypt_failed', $id);
            }
            $inner = json_decode($opened['plain'], true);
            if (!\is_array($inner) || 1 !== ($inner['v'] ?? null) || abs(($inner['ts'] ?? 0) - time()) > 600) {
                return $this->error(403, 'refused', 'stale', 'e2e_stale', $id);
            }
            if (($inner['request']['op'] ?? null) !== $r['op']) {
                return $this->error(403, 'refused', 'op mismatch', 'e2e_op_mismatch', $id);
            }
            $op = $inner['request'];
            $seal = $opened['seal'];
            if ('file_put' === $r['op']) {
                $parts = '';
                $last = false;
                foreach (array_filter(explode("\n", $rec->body), static fn (string $l): bool => '' !== trim($l)) as $line) {
                    $f = $seal->openInput((array) json_decode($line, true));
                    $parts .= $f['data'];
                    $last = $f['last'];
                }
                if (!$last) {
                    return $this->error(400, 'usage', 'the upload ended early', 'body_interrupted', $id);
                }
                $input = $parts;
            }
            $this->sealed[] = $r['op'];
        } else {
            if ($d['required'] ?? false) {
                return $this->error(409, 'refused', 'This desk requires end-to-end encryption for API commands.', 'e2e_required', $id);
            }
            $this->plain[] = $r['op'];
        }
        $events = $this->run($id, $op, $input);
        $final = $events[\count($events) - 1] ?? null;
        $failed = null !== $final && 'error' === $final['event'] ? $final : null;
        $sealOne = function (array $e) use ($seal): array {
            /** @var DeskSeal $seal */
            $f = $seal->sealEvent($e);
            if ('flip' === $this->tamper) {
                $c = (string) Crypto::b64decode($f['ciphertext']);
                $c[0] = \chr(\ord($c[0]) ^ 1);
                $f['ciphertext'] = Crypto::b64url($c);
            }

            return $f;
        };
        $errorAnswer = function (array $extra = []) use ($failed, $seal, $events, $sealOne, $id): array {
            /** @var array{kind: string, message: string, reason?: string} $failed */
            $kind = \in_array($failed['kind'], ['refused', 'usage', 'protocol', 'unreachable', 'connection_lost'], true) ? $failed['kind'] : 'failed';
            $env = $this->envelope($kind, null !== $seal ? 'The desk reported an error (end-to-end encrypted).' : $failed['message'], $failed['reason'] ?? null, $id);
            $env['error'] += $extra;
            if (null !== $seal) {
                $env['e2e'] = ['v' => 1, 'events' => array_map($sealOne, $events)];
            }

            return $env;
        };

        // Streams.
        if (null !== $r['stream']) {
            if ('error' === ($events[0]['event'] ?? null)) {
                return $this->json(self::statusOf($events[0]['kind'], $events[0]['reason'] ?? null), $errorAnswer());
            }
            $chunks = [];
            $map = new PlainMap($r['stream'], $id);
            foreach ($events as $e) {
                $chunks[] = ": keep-alive\r\n\r\n";
                if (null !== $seal && 'plaintext' !== $this->tamper) {
                    array_push($chunks, ...self::sse('sealed', ['event' => 'sealed'] + $sealOne($e)));
                } else {
                    foreach ($map->map($e) as [$name, $v]) {
                        array_push($chunks, ...self::sse($name, $v));
                    }
                }
            }
            if (null === $final || ('exit' !== $final['event'] && 'error' !== $final['event'])) {
                $lost = ['event' => 'error'] + ('exec' === $r['stream'] ? ['exit' => 255] : []) + ['error' => ['kind' => 'connection_lost', 'message' => 'The desk went away during this operation.', 'desk' => $id, 'reason' => 'desk_disconnected']];
                array_push($chunks, ...self::sse('error', $lost));
            }

            return new FakeResponse(200, ['Content-Type' => 'text/event-stream', 'X-Request-Id' => $this->requestId()], $chunks);
        }

        // A download.
        if ('file_get' === $r['op']) {
            if ('error' === ($events[0]['event'] ?? null)) {
                return $this->json(self::statusOf($events[0]['kind'], $events[0]['reason'] ?? null), $errorAnswer());
            }
            if (null === $seal) {
                $chunks = [];
                foreach ($events as $e) {
                    if ('stdout' === $e['event']) {
                        $chunks[] = (string) base64_decode($e['data'], true);
                    }
                }

                return new FakeResponse(200, ['Content-Type' => 'application/octet-stream', 'X-Request-Id' => $this->requestId()], $chunks);
            }
            $keep = 'truncated' === ($op['path'] ?? null) ? \array_slice($events, 0, -1) : $events;
            $chunks = [];
            foreach ($keep as $e) {
                $chunks[] = json_encode($sealOne($e), \JSON_UNESCAPED_SLASHES)."\n";
            }

            return new FakeResponse(200, ['Content-Type' => 'application/x-ndjson', 'X-Request-Id' => $this->requestId()], $chunks);
        }

        $okStatus = 'job_start' === $r['op'] || 'token_mint' === $r['op'] ? 201 : 200;
        $held = 'job_wait' === $r['op'] && \in_array($op['name'] ?? null, ['held', 'held-gone', 'held-fail'], true);
        if ($held) {
            $head = ['Content-Type' => 'application/json', 'X-Request-Id' => $this->requestId(), 'GaiaDesk-Held' => '1'];
            if (null !== $failed) {
                $body = $errorAnswer(['status' => self::statusOf($failed['kind'], $failed['reason'] ?? null)]);
            } else {
                $body = null !== $seal ? ['e2e' => ['v' => 1, 'events' => array_map($sealOne, $events)]] : $final['result'];
            }

            return new FakeResponse(200, $head, [' ', ' ', ' ', (string) json_encode($body, \JSON_UNESCAPED_SLASHES)]);
        }
        if (null !== $failed) {
            return $this->json(self::statusOf($failed['kind'], $failed['reason'] ?? null), $errorAnswer());
        }
        if (null !== $seal && 'plaintext' !== $this->tamper) {
            return $this->json($okStatus, ['e2e' => ['v' => 1, 'events' => array_map($sealOne, $events)]]);
        }

        return $this->json($okStatus, $final['result'] ?? new \stdClass());
    }

    /**
     * One SSE event, written in pieces (split mid-line, `\r\n` across pieces).
     *
     * @param array<string, mixed> $v
     *
     * @return list<string>
     */
    public static function sse(string $name, array $v): array
    {
        $text = "event: $name\r\ndata: ".json_encode($v, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\r\n\r\n";
        $n = \strlen($text);

        return [substr($text, 0, 3), substr($text, 3, intdiv($n, 2) - 3), substr($text, intdiv($n, 2), $n - 1 - intdiv($n, 2)), substr($text, $n - 1)];
    }

    /**
     * The route's operation and its plaintext request.
     *
     * @param array<string, string> $q
     *
     * @return array{op: string, req: array<string, mixed>, stream: ?string}|null
     */
    private function routeOp(string $method, string $rest, array $q, string $body): ?array
    {
        $json = static fn (): array => '' !== $body ? (array) json_decode($body, true) : [];
        if ('/exec' === $rest && 'POST' === $method) {
            $s = '1' === ($q['stream'] ?? null);

            return ['op' => 'exec', 'req' => ['op' => 'exec', 'spec' => $json()] + ($s ? ['stream' => true] : []), 'stream' => $s ? 'exec' : null];
        }
        if ('/jobs' === $rest && 'POST' === $method) {
            return ['op' => 'job_start', 'req' => ['op' => 'job_start', 'spec' => $json()], 'stream' => null];
        }
        if ('/jobs' === $rest && 'GET' === $method) {
            return ['op' => 'job_list', 'req' => ['op' => 'job_list'], 'stream' => null];
        }
        if ('/stats' === $rest && 'GET' === $method) {
            return ['op' => 'stats', 'req' => ['op' => 'stats'], 'stream' => null];
        }
        if ('/files' === $rest && 'PUT' === $method) {
            return ['op' => 'file_put', 'req' => ['op' => 'file_put', 'path' => $q['path'] ?? null, 'size' => \strlen($body)], 'stream' => null];
        }
        if ('/files' === $rest && 'GET' === $method) {
            return ['op' => 'file_get', 'req' => ['op' => 'file_get', 'path' => $q['path'] ?? null], 'stream' => null];
        }
        if ('/tokens' === $rest && 'POST' === $method) {
            return ['op' => 'token_mint', 'req' => ['op' => 'token_mint', 'spec' => $json()], 'stream' => null];
        }
        if ('/tokens' === $rest && 'GET' === $method) {
            return ['op' => 'token_list', 'req' => ['op' => 'token_list'], 'stream' => null];
        }
        if (1 === preg_match('#^/tokens/([^/]+)$#', $rest, $m) && 'DELETE' === $method) {
            return ['op' => 'token_revoke', 'req' => ['op' => 'token_revoke', 'token' => rawurldecode($m[1])], 'stream' => null];
        }
        if (1 !== preg_match('#^/jobs/([^/]+)(/logs|/wait)?$#', $rest, $m)) {
            return null;
        }
        $name = rawurldecode($m[1]);
        $sub = $m[2] ?? '';
        if ('' === $sub && 'DELETE' === $method) {
            return ['op' => 'job_kill', 'req' => ['op' => 'job_kill', 'name' => $name], 'stream' => null];
        }
        if ('/wait' === $sub) {
            return ['op' => 'job_wait', 'req' => ['op' => 'job_wait', 'name' => $name] + (isset($q['timeout']) ? ['timeout_ms' => (int) $q['timeout'] * 1000] : []), 'stream' => null];
        }
        if ('/logs' === $sub) {
            $req = ['op' => 'job_logs', 'name' => $name];
            if (isset($q['tail'])) {
                $req['tail'] = (int) $q['tail'];
            }
            if ('1' === ($q['follow'] ?? null)) {
                $req['follow'] = true;
            }

            return ['op' => 'job_logs', 'req' => $req, 'stream' => '1' === ($q['follow'] ?? null) ? 'logs' : null];
        }

        return null;
    }

    /**
     * What the desk does: its events for one operation (canned, deterministic).
     *
     * @param array<string, mixed> $req
     *
     * @return list<array<string, mixed>>
     */
    private function run(string $desk, array $req, string $input): array
    {
        $out = static fn (string $s): array => ['event' => 'stdout', 'data' => base64_encode($s)];
        $exit = static fn (mixed $result): array => ['event' => 'exit', 'result' => $result];
        switch ($req['op']) {
            case 'exec':
                $spec = \is_array($req['spec'] ?? null) ? $req['spec'] : [];
                $cmd = $spec['command'] ?? implode(' ', $spec['argv'] ?? []);
                if ('refuse' === $cmd) {
                    return [['event' => 'error', 'kind' => 'refused', 'reason' => 'token_refused', 'message' => "this token (bot) has no exec scope on desk $desk"]];
                }
                if (true === ($spec['admin'] ?? false)) {
                    $reason = 'whoami' === $cmd ? null : $cmd;
                    if (null !== $reason) {
                        return [$exit(['desk' => $desk, 'exit' => 254, 'remote_code' => null, 'duration_ms' => 1, 'notes' => [], 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'truncated' => false, 'error' => ['kind' => 'refused', 'reason' => $reason, 'message' => "admin refused: $reason", 'desk' => $desk]])];
                    }
                }
                $text = "ran: $cmd é\n";
                foreach ((array) ($spec['env'] ?? []) as $k => $v) {
                    $text .= "env: $k=$v\n";
                }
                if (\is_string($spec['stdin'] ?? null)) {
                    $text .= "stdin: {$spec['stdin']}\n";
                }
                if (true === ($spec['admin'] ?? false)) {
                    $text .= "as: root\n";
                }
                $code = 'fail' === $cmd ? 3 : 0;
                $result = ['desk' => $desk, 'exit' => $code, 'remote_code' => $code, 'duration_ms' => 7, 'notes' => [], 'stdout' => $text, 'stderr' => "warn\n", 'timed_out' => false, 'truncated' => false, 'error' => null, 'mode' => 'pipes', 'route' => 'the GaiaDesk server', 'shell' => $spec['shell'] ?? null];
                if (!($req['stream'] ?? false)) {
                    return [$exit($result)];
                }
                // The output in pieces, a character split across two of them.
                $cut = strpos($text, 'é') + 1;
                $events = [$out(substr($text, 0, $cut)), ['event' => 'stderr', 'data' => base64_encode("warn\n")], $out(substr($text, $cut))];
                if ('slow' === $cmd) {
                    for ($i = 0; $i < 50; ++$i) {
                        $events[] = $out("tick $i\n");
                    }
                }
                if ('lose' === $cmd) {
                    return $events; // the desk goes away (the server says so in the clear)
                }
                $events[] = $exit($result);

                return $events;
            case 'job_start':
                return [$exit(['name' => $req['spec']['name'], 'state' => 'running', 'pid' => 42, 'command' => implode(' ', (array) $req['spec']['command']), 'started_at_ms' => 1791300000000, 'desk' => $desk])];
            case 'job_list':
                return [$exit(['jobs' => [['name' => 'build', 'state' => 'running', 'pid' => 42, 'command' => 'make', 'started_at_ms' => 1791300000000]]])];
            case 'job_kill':
                return [$exit(['name' => $req['name'], 'state' => 'killed', 'command' => 'make', 'started_at_ms' => 1791300000000, 'desk' => $desk])];
            case 'job_wait':
                if ('held-gone' === $req['name'] || 'nope' === $req['name']) {
                    return [['event' => 'error', 'kind' => 'failed', 'message' => "no job named \"{$req['name']}\""]];
                }
                if ('held-fail' === $req['name']) {
                    return [['event' => 'error', 'kind' => 'connection_lost', 'reason' => 'desk_disconnected', 'message' => 'The desk went away during this operation.']];
                }
                if ('slow' === $req['name']) {
                    usleep(min(1000, (int) ($req['timeout_ms'] ?? 0)) * 1000); // held as long as asked (at most a second here)

                    return [$exit(['job' => ['name' => 'slow', 'state' => 'running', 'command' => 'sleep', 'started_at_ms' => 1791300000000], 'timed_out' => true])];
                }

                return [$exit(['job' => ['name' => $req['name'], 'state' => 'exited', 'exit_code' => 3, 'command' => 'make', 'started_at_ms' => 1791300000000], 'timed_out' => false])];
            case 'job_logs':
                if ('missing' === $req['name']) {
                    return [['event' => 'error', 'kind' => 'failed', 'message' => 'no job named "missing"']];
                }
                if (!($req['follow'] ?? false)) {
                    return [$exit(['job' => ['name' => $req['name'], 'state' => 'running', 'command' => 'make', 'started_at_ms' => 1791300000000], 'output' => 'tail '.($req['tail'] ?? 'all')."\n"])];
                }
                if ('forever' === $req['name']) {
                    $ev = [];
                    for ($i = 0; $i < 50; ++$i) {
                        $ev[] = $out("line $i\n");
                    }

                    return [...$ev, $exit(['interrupted' => true])];
                }

                return [$out("line1\n"), $out(substr("line2 é\n", 0, 7)), $out(substr("line2 é\n", 7)), $exit(['job' => ['name' => $req['name'], 'state' => 'exited', 'exit_code' => 0, 'command' => 'make', 'started_at_ms' => 1791300000000]])];
            case 'stats':
                return [$exit(['desk' => $desk, 'hostname' => 'studio', 'os' => 'macos', 'cpu_percent' => 5, 'cpus' => 8, 'mem_total_mb' => 16384, 'mem_free_mb' => 1024, 'uptime_secs' => 3600, 'jobs_running' => 1])];
            case 'file_put':
                if ('fails' === $req['path']) {
                    return [$exit(['direction' => 'upload', 'desk' => $desk, 'destination' => $req['path'], 'files' => 0, 'dirs' => 0, 'bytes' => 0, 'resumed_bytes' => 0, 'failed' => [['path' => 'fails', 'message' => 'permission denied']], 'seconds' => 0])];
                }
                $this->files[(string) $req['path']] = $input;

                return [$exit(['direction' => 'upload', 'desk' => $desk, 'destination' => $req['path'], 'files' => 1, 'dirs' => 0, 'bytes' => \strlen($input), 'resumed_bytes' => 0, 'failed' => [], 'seconds' => 0])];
            case 'file_get':
                if ('missing' === $req['path']) {
                    return [['event' => 'error', 'kind' => 'failed', 'reason' => 'not_found', 'message' => 'no such file: missing']];
                }
                $data = $this->files[(string) $req['path']] ?? "contents of {$req['path']}\n";
                $ev = [];
                for ($i = 0; $i < \strlen($data); $i += Crypto::INPUT_CHUNK) {
                    $ev[] = $out(substr($data, $i, Crypto::INPUT_CHUNK));
                }
                $ev[] = $exit(['direction' => 'download', 'desk' => $desk, 'destination' => $req['path'], 'files' => 1, 'dirs' => 0, 'bytes' => \strlen($data), 'resumed_bytes' => 0, 'failed' => [], 'seconds' => 0]);

                return $ev;
            case 'token_mint':
                return [$exit(['tokens' => [['desk' => $desk, 'token' => ['id' => 'tok1', 'label' => $req['spec']['name'], 'scopes' => $req['spec']['scopes'], 'issued_at_ms' => 1, 'expires_at_ms' => 2], 'secret' => 'gdagt_minted_secret']]])];
            case 'token_list':
                return [$exit(['tokens' => [['id' => 'tok1', 'label' => 'bot', 'issued_at_ms' => 1, 'expires_at_ms' => 2]]])];
            case 'token_revoke':
                return [$exit(['revoked' => $req['token'], 'stopped_sessions' => 0])];
        }

        return [['event' => 'error', 'kind' => 'protocol', 'reason' => 'unknown_op', 'message' => 'unknown operation']];
    }

    private function auditRoute(Recorded $rec): FakeResponse
    {
        $q = $rec->query;
        $limit = (int) ($q['limit'] ?? 100);
        $events = array_values(array_filter($this->audit, static function (array $e) use ($q): bool {
            if (isset($q['until_ms']) && $e['occurred_at_ms'] > (int) $q['until_ms']) {
                return false;
            }
            if (isset($q['since_ms']) && $e['occurred_at_ms'] < (int) $q['since_ms']) {
                return false;
            }
            if (isset($q['action'])) {
                $a = $q['action'];
                if (str_ends_with($a, '.*') ? !str_starts_with($e['action'], substr($a, 0, -1)) : $e['action'] !== $a) {
                    return false;
                }
            }

            return true;
        }));

        return $this->json(200, ['events' => \array_slice($events, 0, $limit)]);
    }

    private function webhookRoute(Recorded $rec, string $path): FakeResponse
    {
        if ('/v1/webhooks' === $path && 'GET' === $rec->method) {
            return $this->json(200, ['webhooks' => array_values($this->webhooks)]);
        }
        if ('/v1/webhooks' === $path && 'POST' === $rec->method) {
            $b = $rec->json();
            $id = \sprintf('wh_%016x', ++$this->seq);
            $w = ['id' => $id, 'url' => $b['url'] ?? '', 'events' => $b['events'] ?? [], 'description' => $b['description'] ?? '', 'created_at' => 1791300000];
            $this->webhooks[$id] = $w;

            return $this->json(201, $w + ['secret' => 'whsec_'.str_repeat('ab', 32)]);
        }
        if (1 === preg_match('#^/v1/webhooks/(wh_[0-9a-f]{16})$#', $path, $m) && 'DELETE' === $rec->method) {
            if (!isset($this->webhooks[$m[1]])) {
                return $this->error(404, 'unreachable', 'no such webhook', 'unknown_webhook');
            }
            unset($this->webhooks[$m[1]]);

            return $this->json(200, ['deleted' => $m[1]]);
        }

        return $this->error(404, 'unreachable', 'no such route', 'no_such_route');
    }

    private function supportRoute(Recorded $rec, string $path): FakeResponse
    {
        if ('/v1/support/sessions' === $path && 'POST' === $rec->method) {
            $b = $rec->json();
            $id = \sprintf('ss_%016x', ++$this->seq);
            $s = ['id' => $id, 'state' => 'waiting', 'mode' => $b['mode'] ?? 'view', 'customer' => $b['customer'] ?? new \stdClass(), 'customer_present' => false, 'customer_verified' => true, 'join_code' => '123456789', 'join_url' => "https://gaiadesk.net/app/support.html#session=$id", 'desk_id' => null, 'origin' => $b['origin'] ?? null, 'owner' => 'you@example.com', 'created_at' => 1791300000, 'expires_at' => 1791300000 + (int) ($b['expires_in'] ?? 3600)];
            $this->sessions[$id] = $s;

            return $this->json(201, $s + ['embed_token' => 'gdemb_'.str_repeat('cd', 32)]);
        }
        if ('/v1/support/sessions' === $path && 'GET' === $rec->method) {
            return $this->json(200, ['sessions' => array_values($this->sessions)]);
        }
        if (1 === preg_match('#^/v1/support/sessions/(ss_[0-9a-f]{16})$#', $path, $m) && 'GET' === $rec->method) {
            if (!isset($this->sessions[$m[1]])) {
                return $this->error(404, 'unreachable', 'no such support session', 'unknown_support_session');
            }

            return $this->json(200, $this->sessions[$m[1]]);
        }

        return $this->error(404, 'unreachable', 'no such route', 'no_such_route');
    }
}
