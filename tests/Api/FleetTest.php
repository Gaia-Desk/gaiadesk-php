<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Api;

use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;

/** The hosted API's own resources: one desk, reach, wake, audit (and its paging), webhooks, support sessions. */
final class FleetTest extends ApiTestCase
{
    public function testDeskReachAndWake(): void
    {
        $gd = $this->gd();
        $d = $gd->desk(self::OK);
        self::assertSame([self::OK, true, ['doorbell_sockets' => 1, 'lan_wake' => true]], [$d['desk_id'], $d['online'], $d['wake']]);
        $r = $gd->reach(self::OK, since: 1790000000, limit: 20);
        self::assertSame(['since' => '1790000000', 'limit' => '20'], $this->last()->query);
        self::assertSame('silent', $r['events'][0]['reason']);
        $w = $gd->wake(self::OK, waitS: 30, idempotencyKey: 'wake-1');
        self::assertSame(['POST', ['wait_s' => 30], 'wake-1'], [$this->last()->method, $this->body(), $this->last()->header('idempotency-key')]);
        self::assertTrue($w['already_online']);
        $this->throws(UsageException::class, static fn () => $gd->wake(self::OK, waitS: 91));
        $this->throws(UsageException::class, static fn () => $gd->reach(self::OK, limit: 0));
        $this->throws(UnreachableException::class, static fn () => $gd->desk('000000000'));
    }

    public function testAuditAndItsPages(): void
    {
        $gd = $this->gd();
        $page = $gd->auditEvents(action: 'api.*', limit: 10, desk: self::OK);
        self::assertSame(['desk' => self::OK, 'action' => 'api.*', 'limit' => '10'], $this->last()->query);
        self::assertCount(10, $page);
        foreach ($page as $e) {
            self::assertStringStartsWith('api.', $e['action']);
        }
        $before = \count($this->api->requests);
        $all = iterator_to_array($gd->iterateAuditEvents(pageSize: 7), false);
        self::assertCount(250, $all, 'every event once, across pages that split a millisecond');
        self::assertCount(250, array_unique(array_column($all, 'id')));
        self::assertGreaterThan(30, \count($this->api->requests) - $before);
        self::assertCount(5, iterator_to_array($gd->iterateAuditEvents(pageSize: 2, max: 5), false));
        $this->throws(UsageException::class, static fn () => $gd->auditEvents(limit: 501));
    }

    public function testWebhooks(): void
    {
        $gd = $this->gd();
        $w = $gd->createWebhook('https://example.com/hooks', ['desk.online', 'job.finished'], description: 'ops', idempotencyKey: 'wh-1');
        self::assertSame(['url' => 'https://example.com/hooks', 'events' => ['desk.online', 'job.finished'], 'description' => 'ops'], $this->body());
        self::assertMatchesRegularExpression('/^whsec_[0-9a-f]{64}$/', $w['secret']);
        self::assertSame([$w['id']], array_column($gd->webhooks(), 'id'));
        self::assertSame(['deleted' => $w['id']], $gd->deleteWebhook($w['id']));
        self::assertSame([], $gd->webhooks());
        $this->throws(UsageException::class, static fn () => $gd->createWebhook('http://example.com', ['desk.online']));
        $this->throws(UsageException::class, static fn () => $gd->createWebhook('https://example.com', ['desk.exploded']));
        $this->throws(UsageException::class, static fn () => $gd->createWebhook('https://example.com', []));
        $this->throws(UsageException::class, static fn () => $gd->deleteWebhook('nope'));
        $this->throws(UnreachableException::class, static fn () => $gd->deleteWebhook('wh_0000000000000000'));
    }

    public function testSupportSessions(): void
    {
        $gd = $this->gd();
        $s = $gd->createSupportSession(mode: 'cobrowse', customer: ['name' => 'Ada', 'plan' => 'pro', 'seats' => 3], expiresIn: 1800, origin: 'https://app.example.com');
        self::assertSame(['mode' => 'cobrowse', 'customer' => ['name' => 'Ada', 'plan' => 'pro', 'seats' => 3], 'expires_in' => 1800, 'origin' => 'https://app.example.com'], $this->body());
        self::assertMatchesRegularExpression('/^gdemb_[0-9a-f]{64}$/', $s['embed_token']);
        $gd->createSupportSession();
        self::assertSame('{}', $this->last()->body, 'no fields: an empty object');
        self::assertCount(2, $gd->supportSessions(state: 'all', limit: 10));
        self::assertSame(['state' => 'all', 'limit' => '10'], $this->last()->query);
        self::assertSame('waiting', $gd->supportSession($s['id'])['state']);
        $this->throws(UsageException::class, static fn () => $gd->createSupportSession(mode: 'drive'));
        $this->throws(UsageException::class, static fn () => $gd->createSupportSession(expiresIn: 10));
        $this->throws(UsageException::class, static fn () => $gd->supportSessions(state: 'closed'));
        $this->throws(UsageException::class, static fn () => $gd->supportSession('ss_x'));
        $this->throws(UnreachableException::class, static fn () => $gd->supportSession('ss_0000000000000000'));
    }
}
