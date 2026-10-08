<?php

declare(strict_types=1);

namespace GaiaDesk\Concerns;

use GaiaDesk\GaiaDesk;
use GaiaDesk\Internal\Args;
use GaiaDesk\Internal\Json;
use GaiaDesk\Transport\Call;
use GaiaDesk\Types;

/**
 * The hosted API's own resources: one desk and its reachability, waking it, the audit
 * trail, webhooks and support sessions. Not served by a desk's local API or LAN gateway.
 *
 * @internal part of {@see GaiaDesk}
 *
 * @phpstan-import-type ApiDeskDetail from Types
 * @phpstan-import-type ApiReachLog from Types
 * @phpstan-import-type ApiWakeResult from Types
 * @phpstan-import-type ApiAuditEvent from Types
 * @phpstan-import-type ApiWebhook from Types
 * @phpstan-import-type ApiWebhookCreated from Types
 * @phpstan-import-type ApiWebhookDeleted from Types
 * @phpstan-import-type ApiSupportSession from Types
 * @phpstan-import-type ApiSupportSessionCreated from Types
 */
trait Fleet
{
    private function hostedOnly(string $what): void
    {
        if ('api' !== $this->t->transport) {
            throw $this->t->notServed($what, 'it is the hosted API\'s (construct GaiaDesk with an apiKey)');
        }
    }

    /**
     * `GET /desks/{id}`: one desk, with its reachability, its end-to-end key (`e2e_pub`)
     * and how it could be woken (`wake`).
     *
     * @return ApiDeskDetail
     */
    public function desk(string $deskId): array
    {
        $this->hostedOnly('desk()');
        $path = $this->deskPath($deskId);
        $r = self::object($this->t->json(new Call('GET', $path, timeout: 60.0)), "GET $path", 'the desk');

        /** @var ApiDeskDetail $r */
        return $r;
    }

    /**
     * `GET /desks/{id}/reach`: the desk's online and offline history, newest first (the
     * reach log keeps 30 days).
     *
     * @param int|null $since unix seconds (default: seven days ago)
     * @param int|null $limit at most this many transitions (1-1000; default 200)
     *
     * @return ApiReachLog
     */
    public function reach(string $deskId, ?int $since = null, ?int $limit = null): array
    {
        $this->hostedOnly('reach()');
        if (null !== $limit) {
            Args::intIn($limit, 1, 1000, 'limit');
        }
        $path = $this->deskPath($deskId).'/reach';
        $r = self::object($this->t->json(new Call('GET', $path, query: ['since' => $since, 'limit' => $limit], timeout: 60.0)), "GET $path", 'the reach log');

        /** @var ApiReachLog $r */
        return $r;
    }

    /**
     * `POST /desks/{id}/wake`: ring the desk's doorbell and ask up to three awake desks of
     * the account on its network to Wake-on-LAN it. With `$waitS`, wait up to that long (at
     * most 90 s) and say whether it came online (`woke`). A desk with nothing to ring is an
     * UnreachableException (`no_wake_path`).
     *
     * @return ApiWakeResult
     */
    public function wake(string $deskId, int $waitS = 0, ?string $idempotencyKey = null): array
    {
        $this->hostedOnly('wake()');
        Args::intIn($waitS, 0, 90, 'waitS');
        $path = $this->deskPath($deskId).'/wake';
        $r = self::object($this->t->json(new Call('POST', $path, json: ['wait_s' => $waitS], idempotencyKey: self::idempotencyKey($idempotencyKey), timeout: $waitS + 60.0)), "POST $path", 'a wake result');

        /** @var ApiWakeResult $r */
        return $r;
    }

    /**
     * The query of an audit request.
     *
     * @return array<string, string|int|null>
     */
    private static function auditQuery(?string $desk, ?string $actor, ?string $action, ?string $token, ?int $sinceMs, ?int $untilMs, ?int $limit): array
    {
        if (null !== $limit) {
            Args::intIn($limit, 1, 500, 'limit');
        }

        return ['desk' => null !== $desk ? Args::desk($desk) : null, 'actor' => $actor, 'action' => $action, 'token' => $token, 'since_ms' => $sinceMs, 'until_ms' => $untilMs, 'limit' => $limit];
    }

    /**
     * `GET /audit`: audit events about the caller and their own desks, newest first: desk
     * sessions, agent-token activity, and every API call (`api.*`).
     *
     * @param string|null $desk   only events about this desk
     * @param string|null $actor  only events by this actor id (an email, a token id)
     * @param string|null $action only this action, or every action under it when it ends in `.*` (`api.*`)
     * @param string|null $token  only events by this agent token id or API key id
     * @param int|null    $limit  at most this many (1-500; default 100)
     *
     * @return list<ApiAuditEvent>
     */
    public function auditEvents(?string $desk = null, ?string $actor = null, ?string $action = null, ?string $token = null, ?int $sinceMs = null, ?int $untilMs = null, ?int $limit = null): array
    {
        $this->hostedOnly('auditEvents()');
        $q = self::auditQuery($desk, $actor, $action, $token, $sinceMs, $untilMs, $limit);

        /** @var list<ApiAuditEvent> */
        return self::listOf($this->t->json(new Call('GET', '/audit', query: $q, timeout: 60.0)), 'events', 'GET /audit');
    }

    /**
     * Every audit event matching the filters, newest first, fetched a page at a time
     * (paging back by `until_ms`; an event is never yielded twice).
     *
     * @param int      $pageSize events per request (1-500)
     * @param int|null $max      stop after this many events
     *
     * @return \Generator<int, ApiAuditEvent>
     */
    public function iterateAuditEvents(?string $desk = null, ?string $actor = null, ?string $action = null, ?string $token = null, ?int $sinceMs = null, ?int $untilMs = null, int $pageSize = 100, ?int $max = null): \Generator
    {
        Args::intIn($pageSize, 1, 500, 'pageSize');
        $seen = [];
        $n = 0;
        $until = $untilMs;
        while (true) {
            $page = $this->auditEvents($desk, $actor, $action, $token, $sinceMs, $until, $pageSize);
            $fresh = 0;
            $oldest = null;
            foreach ($page as $ev) {
                $at = $ev['occurred_at_ms'];
                $oldest = null === $oldest ? $at : min($oldest, $at);
                if (isset($seen[$ev['id']])) {
                    continue;
                }
                $seen[$ev['id']] = true;
                ++$fresh;
                yield $ev;
                if (null !== $max && ++$n >= $max) {
                    return;
                }
            }
            if (\count($page) < $pageSize || null === $oldest) {
                return;
            }
            // The next page ends at the oldest one seen (inclusive or not: seen ones are skipped);
            // a full page all of one millisecond steps past it.
            $until = 0 === $fresh || $oldest === $until ? $oldest - 1 : $oldest;
            if (null !== $sinceMs && $until < $sinceMs) {
                return;
            }
        }
    }

    /**
     * `GET /webhooks`: the account's webhook subscriptions (never their secrets).
     *
     * @return list<ApiWebhook>
     */
    public function webhooks(): array
    {
        $this->hostedOnly('webhooks()');

        /** @var list<ApiWebhook> */
        return self::listOf($this->t->json(new Call('GET', '/webhooks', timeout: 60.0)), 'webhooks', 'GET /webhooks');
    }

    /**
     * `POST /webhooks`: subscribe an `https://` endpoint to events. The answer's `secret`
     * (`whsec_…`) is shown once: keep it to verify deliveries ({@see \GaiaDesk\Webhooks::verify()}).
     *
     * @param array<mixed> $events any of {@see GaiaDesk::WEBHOOK_EVENTS}
     *
     * @return ApiWebhookCreated
     */
    public function createWebhook(string $url, array $events, ?string $description = null, ?string $idempotencyKey = null): array
    {
        $this->hostedOnly('createWebhook()');
        if (1 !== preg_match('#^https://[^/]#i', $url)) {
            throw Args::usage('a webhook URL is https://');
        }
        $events = Args::stringList($events, 'events');
        if ([] === $events) {
            throw Args::usage('events must be a non-empty list');
        }
        foreach ($events as $e) {
            if (!\in_array($e, GaiaDesk::WEBHOOK_EVENTS, true)) {
                throw Args::usage('not a webhook event: '.Json::encode($e).' (one of '.implode(', ', GaiaDesk::WEBHOOK_EVENTS).')');
            }
        }
        $body = ['url' => $url, 'events' => $events];
        if (null !== $description) {
            $body['description'] = $description;
        }
        $r = self::object($this->t->json(new Call('POST', '/webhooks', json: $body, idempotencyKey: self::idempotencyKey($idempotencyKey), timeout: 60.0)), 'POST /webhooks', 'the webhook');

        /** @var ApiWebhookCreated $r */
        return $r;
    }

    /**
     * `DELETE /webhooks/{webhook_id}`: unsubscribe. Deliveries still queued for it are dropped.
     *
     * @return ApiWebhookDeleted
     */
    public function deleteWebhook(string $webhookId): array
    {
        $this->hostedOnly('deleteWebhook()');
        if (1 !== preg_match('/^wh_[0-9a-f]{16}$/D', $webhookId)) {
            throw Args::usage('a webhook id is wh_ and 16 hex digits');
        }
        $path = '/webhooks/'.$webhookId;
        $r = self::object($this->t->json(new Call('DELETE', $path, timeout: 60.0)), "DELETE $path", 'a deletion');

        /** @var ApiWebhookDeleted $r */
        return $r;
    }

    /**
     * `POST /support/sessions`: create a support session for the embed SDK. Hand the
     * answer's `embed_token` (shown once) to the customer's page; your team joins with
     * `join_code` / `join_url`.
     *
     * @param string|null                                  $mode      `view` (default): the agent sees the shared tab; `cobrowse`: may also guide inside the page
     * @param array<string, string|int|float|bool|null>|null $customer  who the customer is (at most 16 short fields; never in the audit trail)
     * @param int|null                                     $expiresIn seconds, 60 to 86400 (default 3600)
     * @param string|null                                  $origin    the page origin the embed must run on (`https://app.example.com`); none for a desktop app
     *
     * @return ApiSupportSessionCreated
     */
    public function createSupportSession(?string $mode = null, ?array $customer = null, ?int $expiresIn = null, ?string $origin = null, ?string $idempotencyKey = null): array
    {
        $this->hostedOnly('createSupportSession()');
        $body = [];
        if (null !== $mode) {
            if (!\in_array($mode, ['view', 'cobrowse'], true)) {
                throw Args::usage('mode is view or cobrowse');
            }
            $body['mode'] = $mode;
        }
        if (null !== $customer) {
            $body['customer'] = Json::object($customer);
        }
        if (null !== $expiresIn) {
            $body['expires_in'] = Args::intIn($expiresIn, 60, 86400, 'expiresIn');
        }
        if (null !== $origin) {
            $body['origin'] = $origin;
        }
        $r = self::object($this->t->json(new Call('POST', '/support/sessions', json: Json::object($body), idempotencyKey: self::idempotencyKey($idempotencyKey), timeout: 60.0)), 'POST /support/sessions', 'the session');

        /** @var ApiSupportSessionCreated $r */
        return $r;
    }

    /**
     * `GET /support/sessions`: the support sessions of the account and team, newest first.
     *
     * @param string|null       $state open ones (default: waiting or being helped), or all (ended and expired too)
     * @param int|null          $limit at most this many (1-200; default 50)
     *
     * @return list<ApiSupportSession>
     */
    public function supportSessions(?string $state = null, ?int $limit = null): array
    {
        $this->hostedOnly('supportSessions()');
        if (null !== $state && !\in_array($state, ['open', 'all'], true)) {
            throw Args::usage('state is open or all');
        }
        if (null !== $limit) {
            Args::intIn($limit, 1, 200, 'limit');
        }

        /** @var list<ApiSupportSession> */
        return self::listOf($this->t->json(new Call('GET', '/support/sessions', query: ['state' => $state, 'limit' => $limit], timeout: 60.0)), 'sessions', 'GET /support/sessions');
    }

    /**
     * `GET /support/sessions/{session_id}`: a support session's state.
     *
     * @return ApiSupportSession
     */
    public function supportSession(string $sessionId): array
    {
        $this->hostedOnly('supportSession()');
        if (1 !== preg_match('/^ss_[0-9a-f]{16}$/D', $sessionId)) {
            throw Args::usage('a support session id is ss_ and 16 hex digits');
        }
        $path = '/support/sessions/'.$sessionId;
        $r = self::object($this->t->json(new Call('GET', $path, timeout: 60.0)), "GET $path", 'the session');

        /** @var ApiSupportSession $r */
        return $r;
    }
}
