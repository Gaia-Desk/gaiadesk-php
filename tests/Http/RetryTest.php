<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Http;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Http\CurlClient;
use GaiaDesk\Tests\Support\BoundedCalls;
use GaiaDesk\Tests\Support\RawServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The retry policy on the wire, against a raw TCP server: what is sent again (a connection
 * never made: any method; lost after sending, 502/503/504: GETs; 429 and 409
 * idempotency_key_in_flight: any method) and what never is (timeouts, anything else),
 * counted per method by the server.
 */
final class RetryTest extends TestCase
{
    use BoundedCalls;

    private const D = '123456789';
    private const EXEC_OK = '{"desk":"123456789","exit":0,"remote_code":0,"stdout":"ok\n","stderr":"","timed_out":false,"error":null}';

    /** @var list<RawServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $s) {
            $s->stop();
        }
    }

    private function server(string $mode, int $port = 0, int $delayMs = 0): RawServer
    {
        return $this->servers[] = new RawServer($mode, $port, $delayMs);
    }

    private function gd(string $url, int $retries = 2, float $base = 0.005, ?float $response = 30.0, ?float $idle = 30.0): GaiaDesk
    {
        return new GaiaDesk(apiKey: 'ak_t', deskToken: 'gdagt_t', baseUrl: $url, e2e: 'off', maxRetries: $retries, retryBaseDelay: $base, responseTimeout: $response, idleTimeout: $idle);
    }

    public function testAConnectionRefusedIsRetriedForAnyMethodUntilTheServerAppears(): void
    {
        $port = RawServer::freePort();
        $s = $this->server('json '.self::EXEC_OK, $port, 150);
        $gd = $this->gd("http://127.0.0.1:$port/v1", retries: 5, base: 0.1);
        $r = $this->bounded(static fn () => $gd->exec(self::D, 'deploy'));
        self::assertSame("ok\n", $r['stdout']);
        self::assertSame(1, $s->count('POST'), 'the POST reached the server once');
        // No retries: refused, at once.
        $gone = RawServer::freePort();
        [$e, $took] = $this->fails(UnreachableException::class, fn () => $this->gd("http://127.0.0.1:$gone/v1", retries: 0)->exec(self::D, 'deploy'));
        self::assertSame(['network', 'network'], [$e->getKind(), $e->getReason()]);
        self::assertLessThan(1.0, $took);
    }

    /** @return iterable<string, array{string}> */
    public static function dropped(): iterable
    {
        yield 'closed' => ['close'];
        yield 'reset' => ['reset'];
    }

    #[DataProvider('dropped')]
    public function testLostAfterSendingOnlyGetsAreRetried(string $mode): void
    {
        $s = $this->server($mode);
        $gd = $this->gd($s->url);
        $this->fails(UnreachableException::class, static fn () => $gd->stats(self::D));
        self::assertSame(3, $s->count('GET'));
        $this->fails(UnreachableException::class, static fn () => $gd->exec(self::D, 'deploy'));
        $this->fails(UnreachableException::class, static fn () => $gd->exec(self::D, 'deploy', idempotencyKey: 'k-1'));
        self::assertSame(2, $s->count('POST'), 'an exec, keyed or not, is sent once');
        $this->fails(UnreachableException::class, static fn () => $gd->uploadFrom('bytes', self::D, '/tmp/x'));
        self::assertSame(1, $s->count('PUT'));
        $this->fails(UnreachableException::class, static fn () => $gd->revokeToken(self::D, 'tok_1'));
        $this->fails(UnreachableException::class, static fn () => $gd->killJob(self::D, 'nightly'));
        self::assertSame(2, $s->count('DELETE'));
        self::assertSame(3, $s->count('GET'));
    }

    /** @return iterable<string, array{int}> */
    public static function gateways(): iterable
    {
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
    }

    #[DataProvider('gateways')]
    public function testA502503Or504IsRetriedForGetsOnly(int $status): void
    {
        $s = $this->server("status $status");
        $gd = $this->gd($s->url);
        [$e] = $this->fails(GaiaDeskException::class, static fn () => $gd->stats(self::D));
        self::assertSame($status, $e->getStatus());
        self::assertSame(3, $s->count('GET'));
        $this->fails(GaiaDeskException::class, static fn () => $gd->exec(self::D, 'deploy'));
        self::assertSame(1, $s->count('POST'));
        // A followed log is a GET too: retried before its answer begins.
        $exit = $this->bounded(static fn () => $gd->followJobLogs(self::D, 'build')->wait());
        self::assertNotNull($exit->error);
        self::assertSame(6, $s->count('GET'));
    }

    public function testA503ThatIsPermanentIsNotRetriedAndRetryAfterIsHonoured(): void
    {
        $s = $this->server('status 503 - desk_ops_disabled');
        $gd = $this->gd($s->url);
        [$e] = $this->fails(UnreachableException::class, static fn () => $gd->stats(self::D));
        self::assertSame('desk_ops_disabled', $e->getReason());
        self::assertSame(1, $s->count('GET'));
        $s->mode('status 503 1');
        [$e, $took] = $this->fails(UnreachableException::class, fn () => $this->gd($s->url, retries: 1)->stats(self::D));
        self::assertSame(1.0, $e->getRetryAfter());
        self::assertGreaterThanOrEqual(0.95, $took, 'waited as Retry-After said');
        self::assertSame(3, $s->count('GET'));
    }

    public function testA429IsRetriedForAnyMethodAfterRetryAfterUnlessThatIsTooLong(): void
    {
        $s = $this->server('status 429 0 rate_limited');
        $gd = $this->gd($s->url);
        [$e] = $this->fails(RefusedException::class, static fn () => $gd->exec(self::D, 'deploy'));
        self::assertSame('rate_limited', $e->getReason());
        self::assertSame(3, $s->count('POST'));
        $s->mode('status 429 120 desk_busy');
        [$e, $took] = $this->fails(RefusedException::class, static fn () => $gd->exec(self::D, 'deploy'));
        self::assertSame(120.0, $e->getRetryAfter(), 'the error carries the wait it was not given');
        self::assertLessThan(1.0, $took);
        self::assertSame(4, $s->count('POST'));
    }

    public function testA409IdempotencyKeyInFlightIsRetriedForAnyMethod(): void
    {
        $s = $this->server('status 409 - idempotency_key_in_flight');
        $gd = $this->gd($s->url);
        $this->fails(RefusedException::class, static fn () => $gd->exec(self::D, 'deploy', idempotencyKey: 'k-2'));
        self::assertSame(3, $s->count('POST'));
        $this->fails(RefusedException::class, static fn () => $gd->uploadFrom('bytes', self::D, '/tmp/x'));
        self::assertSame(3, $s->count('PUT'));
    }

    public function testTimeoutsAreNeverRetried(): void
    {
        $s = $this->server('silent');
        $this->fails(UnreachableException::class, fn () => $this->gd($s->url, response: 1.0)->stats(self::D));
        self::assertSame(1, $s->count('GET'));
        $s->mode('stall-json');
        $this->fails(ConnectionLostException::class, fn () => $this->gd($s->url, idle: 1.0)->stats(self::D));
        self::assertSame(2, $s->count('GET'));
    }

    /**
     * Requests on a connection the server keeps alive and then drops: libcurl re-sends one
     * that went on a REUSED connection closed before any answer, body and all. Given a
     * shared connection cache, the SDK still sends every non-GET on a fresh connection, so
     * each reaches the server exactly once; a GET may be re-sent by libcurl (a read).
     */
    public function testOnAKeptAliveConnectionThatIsThenDroppedNoNonGetIsSentTwice(): void
    {
        if (!\defined('CURL_LOCK_DATA_CONNECT')) {
            self::markTestSkipped('this libcurl cannot share connections');
        }
        $s = $this->server('keepalive');
        $share = curl_share_init();
        curl_share_setopt($share, \CURLSHOPT_SHARE, \CURL_LOCK_DATA_CONNECT);
        $gd = new GaiaDesk(apiKey: 'ak_t', deskToken: 'gdagt_t', baseUrl: $s->url, e2e: 'off', retryBaseDelay: 0.005, httpClient: new CurlClient(curlOptions: [\CURLOPT_SHARE => $share]));
        $calls = [
            'DELETE' => static fn () => $gd->revokeToken(self::D, 'tok_1'),
            'POST' => static fn () => $gd->exec(self::D, 'deploy'),
            'PUT' => static fn () => $gd->uploadFrom('bytes', self::D, '/tmp/x'),
        ];
        foreach ($calls as $method => $call) {
            $before = $s->count('GET');
            $this->bounded(static fn () => $gd->stats(self::D)); // leaves a kept-alive connection in the cache
            self::assertGreaterThan($before, $s->count('GET'));
            try {
                $this->bounded($call);
            } catch (GaiaDeskException) {
                // {} is not every operation's answer: only how many times it was sent matters here.
            }
            self::assertSame(1, $s->count($method), "the $method reached the server exactly once");
        }
    }

    public function testWithRetriesOffEveryFailureIsOneAttempt(): void
    {
        $s = $this->server('close');
        $gd = $this->gd($s->url, retries: 0);
        $n = 0;
        foreach (['close', 'reset', 'status 502', 'status 503', 'status 504', 'status 429 0', 'status 409 - idempotency_key_in_flight'] as $mode) {
            $s->mode($mode);
            $this->fails(GaiaDeskException::class, static fn () => $gd->stats(self::D));
            self::assertSame(++$n, $s->count('GET'), $mode);
        }
    }
}
