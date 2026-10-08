<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Api;

use GaiaDesk\E2e\Crypto;
use GaiaDesk\E2e\E2eLayer;
use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\E2eException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Tests\Support\FakeApi;
use GaiaDesk\Tests\Support\MockClient;

/**
 * End-to-end encryption, against a fake API that is also the desk: every operation sealed
 * gives exactly what it gives in the clear, the API never sees the command, env, stdin,
 * path or file bytes, and the modes, pins and retries behave.
 */
final class EndToEndTest extends ApiTestCase
{
    private const CANARY = 'canary-7f3a9';
    private const SEALED = '111111111';
    private const OLD = '222222222';
    private const MUST = '333333333';
    private const ASLEEP = '444444444';
    private const GONE = '555555555';
    private const STALE = '666666666';
    private const ROTATED = '777777777';

    private string $key;
    private string $key2;

    protected function setUp(): void
    {
        E2eLayer::resetWarnings();
        $this->key = (string) hex2bin('0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20');
        $this->key2 = (string) hex2bin('2122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f40');
        $this->api = new FakeApi([
            self::SEALED => ['secret' => $this->key],
            self::OLD => [],
            self::MUST => ['secret' => $this->key, 'required' => true],
            self::ASLEEP => ['secret' => $this->key, 'required' => true, 'online' => false, 'wakeable' => true],
            self::GONE => ['secret' => $this->key, 'online' => false],
            self::STALE => ['secret' => $this->key, 'required' => true, 'hideKeyLookups' => 1],
            self::ROTATED => ['secret' => $this->key],
        ]);
        $this->http = new MockClient($this->api);
    }

    /**
     * Run $f and assert the API saw nothing of the canary in any request.
     *
     * @template T
     *
     * @param callable(): T $f
     *
     * @return T
     */
    private function blind(callable $f): mixed
    {
        $before = \count($this->api->requests);
        $r = $f();
        $seen = \array_slice($this->api->requests, $before);
        self::assertNotEmpty($seen);
        foreach ($seen as $q) {
            self::assertStringNotContainsString(self::CANARY, $q->raw(), "the API saw the canary in {$q->method} {$q->path}");
        }

        return $r;
    }

    /**
     * The same call sealed (the API saw none of it) and in the clear: equal answers.
     *
     * @template T
     *
     * @param callable(GaiaDesk): T $f
     *
     * @return T
     */
    /** @param array<string, mixed> $o */
    private function same(callable $f, array $o = []): mixed
    {
        $before = \count($this->api->sealed);
        $sealed = $this->blind(fn () => $f($this->gd($o)));
        self::assertGreaterThan($before, \count($this->api->sealed), 'it went sealed');
        $plain = $f($this->gd(['e2e' => 'off'] + $o));
        self::assertEquals($plain, $sealed);

        return $sealed;
    }

    /** @return array{class: string, kind: string, reason: ?string, status: ?int, message: string, desk: ?string, exit: ?int} */
    private static function shape(GaiaDeskException $e): array
    {
        return ['class' => $e::class, 'kind' => $e->getKind(), 'reason' => $e->getReason(), 'status' => $e->getStatus(), 'message' => $e->getMessage(), 'desk' => $e->getDesk(), 'exit' => $e->getExitCode()];
    }

    /** @return array{class: string, kind: string, reason: ?string, status: ?int, message: string, desk: ?string, exit: ?int} */
    private function sameError(callable $f): array
    {
        $sealed = $this->blind(function () use ($f) {
            try {
                $f($this->gd());
            } catch (GaiaDeskException $e) {
                return self::shape($e);
            }
            self::fail('no error');
        });
        try {
            $f($this->gd(['e2e' => 'off']));
            self::fail('no error');
        } catch (GaiaDeskException $e) {
            self::assertEquals(self::shape($e), $sealed);
        }

        return $sealed;
    }

    public function testExecSealedInAPostBodyLookedUpOnce(): void
    {
        $c = self::CANARY;
        $r = $this->same(static fn (GaiaDesk $g) => $g->exec(self::SEALED, "echo $c", env: ['SECRET' => $c], stdin: $c, cwd: "/srv/$c", timeout: 30));
        self::assertSame("ran: echo $c é\nenv: SECRET=$c\nstdin: $c\n", $r['stdout']);
        $sent = array_values(array_filter($this->api->requests, static fn ($q) => '/v1/desks/111111111/exec' === $q->path))[0];
        $body = $sent->json();
        self::assertSame(['e2e'], array_keys($body));
        $keys = array_keys($body['e2e']);
        sort($keys);
        self::assertSame(['ciphertext', 'nonce', 'pub', 'v'], $keys);
        $gd = $this->gd();
        $gd->exec(self::SEALED, 'once');
        $n = \count(array_filter($this->api->requests, static fn ($q) => 'GET' === $q->method && '/v1/desks/111111111' === $q->path));
        $gd->exec(self::SEALED, 'again');
        self::assertSame($n, \count(array_filter($this->api->requests, static fn ($q) => 'GET' === $q->method && '/v1/desks/111111111' === $q->path)), 'the key is cached');
    }

    public function testAdminNotViaApiSealed(): void
    {
        $e = $this->sameError(static fn (GaiaDesk $g) => $g->exec(self::SEALED, 'admin_not_via_api'));
        self::assertSame([RefusedException::class, 'admin_not_via_api', 254], [$e['class'], $e['reason'], $e['exit']]);
    }

    public function testExecStreamSealed(): void
    {
        $c = self::CANARY;
        $r = $this->same(static fn (GaiaDesk $g) => self::drain($g->execStream(self::SEALED, "echo $c", env: ['K' => $c])));
        self::assertSame("ran: echo $c é\nenv: K=$c\n", $r['out']);
        self::assertSame("warn\n", $r['err']);
        self::assertSame(0, $r['exit']->exitCode);
        $lost = $this->same(static fn (GaiaDesk $g) => self::drain($g->execStream(self::SEALED, 'lose')));
        self::assertSame('connection_lost', $lost['exit']->error['kind'] ?? null);
    }

    public function testJobsLogsWaitKillStatsSealed(): void
    {
        $c = self::CANARY;
        $this->same(static fn (GaiaDesk $g) => $g->runJob(self::SEALED, 'build', "make $c", env: ['CI' => $c], shell: 'bash', cwd: $c));
        $this->same(static fn (GaiaDesk $g) => $g->jobs(self::SEALED));
        self::assertSame("tail 10\n", $this->same(static fn (GaiaDesk $g) => $g->jobLogs(self::SEALED, 'build', tail: 10)));
        $tail = array_values(array_filter($this->api->requests, static fn ($q) => str_ends_with($q->path, '/logs')))[0];
        self::assertSame([], $tail->query, 'tail travels inside the sealed request');
        self::assertNotNull($tail->header('gaiadesk-e2e'));
        $f = $this->same(static fn (GaiaDesk $g) => self::drain($g->followJobLogs(self::SEALED, 'build')));
        self::assertSame("line1\nline2 é\n", $f['out']);
        $w = $this->same(static fn (GaiaDesk $g) => $g->waitJob(self::SEALED, 'build', timeout: 60));
        self::assertSame(['exited', false], [$w['job']['state'], $w['timed_out']]);
        self::assertSame('held', $this->same(static fn (GaiaDesk $g) => $g->waitJob(self::SEALED, 'held'))['job']['name']);
        $this->same(static fn (GaiaDesk $g) => $g->killJob(self::SEALED, 'build'));
        $this->same(static fn (GaiaDesk $g) => $g->stats(self::SEALED));
    }

    public function testDeskErrorsKeepTheirClassKindReasonStatusAndMessage(): void
    {
        $e = $this->sameError(static fn (GaiaDesk $g) => $g->exec(self::SEALED, 'refuse'));
        self::assertSame([RefusedException::class, 'refused', 'token_refused', 403, self::SEALED], [$e['class'], $e['kind'], $e['reason'], $e['status'], $e['desk']]);
        self::assertStringContainsString('no exec scope', $e['message']);
        $w = $this->sameError(static fn (GaiaDesk $g) => $g->waitJob(self::SEALED, 'held-gone'));
        self::assertSame('failed', $w['kind']);
        self::assertStringContainsString('no job named "held-gone"', $w['message']);
        self::assertSame('no job named "missing"', $this->sameError(static fn (GaiaDesk $g) => $g->jobLogs(self::SEALED, 'missing'))['message']);
        $s = $this->same(static fn (GaiaDesk $g) => self::drain($g->followJobLogs(self::SEALED, 'missing')));
        self::assertSame('no job named "missing"', $s['exit']->error['message'] ?? null);
    }

    public function testFilesSealed(): void
    {
        $c = self::CANARY;
        $big = '';
        for ($i = 0; $i < 150 * 1024; ++$i) {
            $big .= \chr($i % 251);
        }
        $marked = "$c file\n";
        $up = $this->same(static fn (GaiaDesk $g) => $g->uploadFrom($marked, self::SEALED, "docs/$c.txt"));
        self::assertSame(\strlen($marked), $up['bytes']);
        $puts = array_values(array_filter($this->api->requests, static fn ($q) => 'PUT' === $q->method));
        self::assertSame(Crypto::FRAMES_CONTENT_TYPE, $puts[0]->header('content-type'));
        self::assertSame([], $puts[0]->query, 'the path travels sealed');
        $this->blind(fn () => $this->gd()->uploadFrom($big, self::SEALED, 'big.bin'));
        $frames = explode("\n", trim($this->last()->body));
        self::assertCount(4, $frames, '48 KiB per frame');
        self::assertSame($big, $this->blind(fn () => $this->gd()->downloadBytes(self::SEALED, 'big.bin')));
        self::assertSame($marked, $this->same(static fn (GaiaDesk $g) => $g->downloadBytes(self::SEALED, "docs/$c.txt")));
        $this->same(static fn (GaiaDesk $g) => $g->uploadFrom('', self::SEALED, 'empty'));
        self::assertSame('', $this->gd()->downloadBytes(self::SEALED, 'empty'), 'an empty file is one empty last frame');
        $this->sameError(static fn (GaiaDesk $g) => $g->downloadBytes(self::SEALED, 'missing'));
        $e = $this->throws(ConnectionLostException::class, fn () => $this->gd()->downloadBytes(self::SEALED, 'truncated'));
        self::assertSame('incomplete', $e->getReason());
    }

    public function testTokensSealed(): void
    {
        $c = self::CANARY;
        $o = ['apiKey' => 'session-person', 'deskToken' => null];
        $m = $this->same(static fn (GaiaDesk $g) => $g->createToken(self::SEALED, "bot-$c", expires: '1h'), $o);
        self::assertSame("bot-$c", $m['tokens'][0]['token']['label'] ?? null);
        $this->same(static fn (GaiaDesk $g) => $g->listTokens(self::SEALED), $o);
        $this->same(static fn (GaiaDesk $g) => $g->revokeToken(self::SEALED, 'tok1'), $o);
        self::assertCount(3, array_filter($this->api->sealed, static fn ($op) => str_starts_with($op, 'token_')));
    }

    public function testAutoWithoutAKeyIsInTheClearWarnedOnce(): void
    {
        $gd = $this->gd();
        $before = \count($this->api->plain);
        $gd->stats(self::OLD);
        $gd->stats(self::OLD);
        self::assertCount($before + 2, $this->api->plain);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('not end-to-end encrypted', $this->warnings[0]);
    }

    public function testRequireNeverSendsInTheClear(): void
    {
        $c = self::CANARY;
        $before = \count($this->api->requests);
        $e = $this->throws(E2eException::class, fn () => $this->gd(['e2e' => 'require'])->exec(self::OLD, "echo $c"));
        self::assertSame(['e2e_unavailable', self::OLD, 254], [$e->getReason(), $e->getDesk(), $e->getExitCode()]);
        $e = $this->throws(E2eException::class, fn () => $this->gd(['e2e' => 'require'])->stats(self::GONE));
        self::assertStringContainsString('offline', $e->getMessage());
        foreach (\array_slice($this->api->requests, $before) as $q) {
            self::assertMatchesRegularExpression('#^/v1/desks/\d+(/wake)?$#', $q->path, 'only lookups and wakes');
        }
        self::assertContains(self::OLD, $this->api->wakes);
        self::assertContains(self::GONE, $this->api->wakes);
        $r = $this->blind(fn () => $this->gd(['e2e' => 'require'])->exec(self::ASLEEP, "echo $c"));
        self::assertSame(0, $r['exit']);
        self::assertContains(self::ASLEEP, $this->api->wakes);
    }

    public function testADeskThatRequiresItAndTheE2eRequiredRetry(): void
    {
        $before = \count($this->api->sealed);
        $this->gd()->stats(self::MUST);
        self::assertCount($before + 1, $this->api->sealed);
        $count = fn () => \count(array_filter($this->api->requests, static fn ($q) => '/v1/desks/666666666/stats' === $q->path));
        $n = $count();
        self::assertSame(5, $this->gd()->stats(self::STALE)['cpu_percent']);
        self::assertSame($n + 2, $count(), 'refused once, then sealed');
        self::assertSame('stats', $this->api->sealed[\count($this->api->sealed) - 1]);
        $e = $this->throws(RefusedException::class, fn () => $this->gd(['e2e' => 'off'])->stats(self::MUST));
        self::assertSame(['e2e_required', 409], [$e->getReason(), $e->getStatus()]);
    }

    public function testARotatedKeyIsFetchedAgainOnce(): void
    {
        $gd = $this->gd();
        $gd->stats(self::ROTATED);
        $this->api->desks[self::ROTATED]['secret'] = $this->key2;
        $count = fn () => \count(array_filter($this->api->requests, static fn ($q) => '/v1/desks/777777777/stats' === $q->path));
        $n = $count();
        self::assertSame(5, $gd->stats(self::ROTATED)['cpu_percent']);
        self::assertSame($n + 2, $count());
    }

    public function testPinnedKeys(): void
    {
        $pub = Crypto::b64url(Crypto::x25519Public($this->key));
        $pub2 = Crypto::b64url(Crypto::x25519Public($this->key2));
        $before = \count($this->api->requests);
        $e = $this->throws(E2eException::class, fn () => $this->gd(['e2eKeys' => [self::SEALED => $pub2]])->exec(self::SEALED, 'echo '.self::CANARY));
        self::assertSame('e2e_key_mismatch', $e->getReason());
        foreach (\array_slice($this->api->requests, $before) as $q) {
            self::assertSame('/v1/desks/111111111', $q->path, 'only the lookup');
        }
        self::assertSame(5, $this->gd(['e2eKeys' => [self::SEALED => $pub]])->stats(self::SEALED)['cpu_percent']);
        $r = $this->blind(fn () => $this->gd(['e2e' => 'require', 'e2eKeys' => [self::GONE => $pub]])->exec(self::GONE, 'echo '.self::CANARY));
        self::assertSame(0, $r['exit'], 'the pin seals while no key is listed');
    }

    public function testAHostileServerIsRefused(): void
    {
        $gd = $this->gd();
        $this->api->tamper = 'flip';
        $e = $this->throws(ProtocolException::class, static fn () => $gd->stats(self::SEALED));
        self::assertSame('e2e_decrypt_failed', $e->getReason());
        self::assertSame('protocol', self::drain($gd->execStream(self::SEALED, 'x'))['exit']->error['kind'] ?? null);
        $e = $this->throws(RefusedException::class, static fn () => $gd->exec(self::SEALED, 'refuse'));
        self::assertStringContainsString('did not open', $e->getMessage(), 'the placeholder, said to be unopened');
        $this->api->tamper = 'plaintext';
        $e = $this->throws(ProtocolException::class, static fn () => $gd->stats(self::SEALED));
        self::assertSame('e2e_unsealed_answer', $e->getReason());
        $p = self::drain($gd->execStream(self::SEALED, 'x'));
        self::assertSame('', $p['out'], 'no forged output');
        self::assertSame('protocol', $p['exit']->error['kind'] ?? null);
        $this->api->tamper = null;
        self::assertSame(5, $gd->stats(self::SEALED)['cpu_percent'], 'honest again, fine again');
    }
}
