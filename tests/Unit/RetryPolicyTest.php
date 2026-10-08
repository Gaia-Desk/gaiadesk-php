<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Unit;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Transport\Retry;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

/** The retry policy as a function: which failures are sent again, after how long. */
final class RetryPolicyTest extends TestCase
{
    public function testTheDefaultsAndTheBackoff(): void
    {
        $r = new Retry();
        self::assertSame([2, 0.25, 8.0, 60.0], [$r->maxRetries, $r->baseDelay, $r->maxDelay, $r->maxRetryWait]);
        $low = new Retry(random: static fn (): float => 0.0);
        $high = new Retry(random: static fn (): float => 1.0);
        // min(8 s, 250 ms * 2^n), times 0.5 to 1.0.
        foreach ([0 => 0.25, 1 => 0.5, 2 => 1.0, 4 => 4.0, 5 => 8.0, 9 => 8.0, 100 => 8.0] as $n => $full) {
            self::assertEqualsWithDelta($full / 2, $low->backoff($n), 1e-9, "n=$n");
            self::assertEqualsWithDelta($full, $high->backoff($n), 1e-9, "n=$n");
        }
        for ($i = 0; $i < 500; ++$i) {
            $d = $r->backoff(1);
            self::assertGreaterThanOrEqual(0.25, $d);
            self::assertLessThanOrEqual(0.5, $d);
        }
    }

    private static function lostAfterSending(): UnreachableException
    {
        return new UnreachableException('lost', ['kind' => 'network', 'reason' => 'network'], new NetworkException(new Request('GET', 'http://x/'), 'reset'));
    }

    private static function neverConnected(): UnreachableException
    {
        return new UnreachableException('refused', ['kind' => 'network', 'reason' => 'network'], new NetworkException(new Request('GET', 'http://x/'), 'refused', false, true));
    }

    /** @param array<string, mixed> $d */
    private static function answered(int $status, ?string $reason = null, ?float $retryAfter = null, array $d = []): GaiaDeskException
    {
        return new GaiaDeskException("HTTP $status", $d + ['status' => $status, 'reason' => $reason, 'retryAfter' => $retryAfter]);
    }

    public function testWhatIsRetriedForWhichMethod(): void
    {
        $r = new Retry(random: static fn (): float => 1.0);
        $cases = [
            // [error, retried for GET, retried for POST/PUT/DELETE]
            'never connected' => [self::neverConnected(), true, true],
            'lost after sending' => [self::lostAfterSending(), true, false],
            '502' => [self::answered(502), true, false],
            '503' => [self::answered(503), true, false],
            '503 api_disabled' => [self::answered(503, 'api_disabled'), false, false],
            '503 desk_ops_disabled' => [self::answered(503, 'desk_ops_disabled'), false, false],
            '503 local_api_off' => [self::answered(503, 'local_api_off'), false, false],
            '504' => [self::answered(504), true, false],
            '429' => [self::answered(429, 'rate_limited'), true, true],
            '409 in flight' => [self::answered(409, 'idempotency_key_in_flight'), true, true],
            '409 other' => [self::answered(409, 'conflict'), false, false],
            '500' => [self::answered(500), false, false],
            '404' => [self::answered(404, 'unknown_desk'), false, false],
            'response timeout' => [new UnreachableException('t', ['kind' => 'timeout', 'reason' => 'timeout'], new NetworkException(new Request('GET', 'http://x/'), 't', true, false, null, false, 'responseTimeout')), false, false],
            'connect timeout' => [new UnreachableException('t', ['kind' => 'timeout', 'reason' => 'timeout'], new NetworkException(new Request('GET', 'http://x/'), 't', true, false, null, false, 'connectTimeout')), false, false],
            'idle timeout' => [new ConnectionLostException('t', ['kind' => 'timeout', 'reason' => 'timeout']), false, false],
            'broke off' => [new ConnectionLostException('t', ['kind' => 'connection_lost', 'reason' => 'incomplete']), false, false],
            'pin mismatch' => [new UnreachableException('pin', ['kind' => 'unreachable', 'reason' => 'fingerprint_mismatch']), false, false],
        ];
        foreach ($cases as $name => [$e, $get, $other]) {
            self::assertSame($get, null !== $r->delay($e, 'GET', 0), "$name: GET");
            foreach (['POST', 'PUT', 'DELETE'] as $m) {
                self::assertSame($other, null !== $r->delay($e, $m, 0), "$name: $m");
            }
            self::assertNull($r->delay($e, 'GET', 2), "$name: after maxRetries");
        }
    }

    public function testRetryAfterIsWaitedForUpToTheMaxRetryWait(): void
    {
        $r = new Retry(random: static fn (): float => 1.0);
        self::assertSame(7.0, $r->delay(self::answered(429, 'desk_busy', 7.0), 'POST', 0));
        self::assertSame(60.0, $r->delay(self::answered(503, null, 60.0), 'GET', 0));
        self::assertNull($r->delay(self::answered(429, 'rate_limited', 61.0), 'GET', 0), 'longer than 60 s: not waited for');
        self::assertNull($r->delay(self::answered(503, null, 120.0), 'GET', 0));
        self::assertSame(0.0, $r->delay(self::answered(429, null, 0.0), 'GET', 0));
        // Without Retry-After: backoff.
        self::assertSame(0.5, $r->delay(self::answered(429), 'POST', 1));
        self::assertNull((new Retry(maxRetryWait: 1.0))->delay(self::answered(429, null, 2.0), 'GET', 0));
        self::assertNull((new Retry(maxRetries: 0))->delay(self::neverConnected(), 'GET', 0));
    }

    public function testTheKnobsAreChecked(): void
    {
        foreach (['retryBaseDelay' => -1.0, 'retryMaxDelay' => \NAN, 'maxRetryWait' => \INF] as $name => $bad) {
            try {
                new GaiaDesk(...['apiKey' => 'ak', $name => $bad]);
                self::fail("accepted $name");
            } catch (UsageException $e) {
                self::assertStringContainsString($name, $e->getMessage());
            }
        }
        $this->expectException(UsageException::class);
        new GaiaDesk(apiKey: 'ak', maxRetries: -1);
    }

    public function testRefusedBeforeActingIsTheOnlyRetryForANonGetAfterSending(): void
    {
        $r = new Retry();
        self::assertNotNull($r->delay(new RefusedException('busy', ['status' => 429, 'reason' => 'desk_busy']), 'DELETE', 0));
        self::assertNull($r->delay(new RefusedException('no', ['status' => 403, 'reason' => 'missing_scope']), 'GET', 0));
    }
}
