<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Http;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Http\CurlClient;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\SocketClient;
use GaiaDesk\Tests\Support\RawServer;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A server or proxy that drops or stalls a connection, on a raw socket (no HTTP
 * framework): the SDK fails with a typed transport error within its timeouts, retries
 * only where its policy allows, never sends a request with a body twice, and never hangs.
 * Every call is bounded (SIGALRM, where pcntl exists) so a hang fails the test in 10 s.
 */
final class RawServerTest extends TestCase
{
    private const D = '123456789';
    private const BOUND = 10;

    /** @var list<RawServer> */
    private array $servers = [];
    private bool $hung = false;

    protected function tearDown(): void
    {
        foreach ($this->servers as $s) {
            $s->stop();
        }
    }

    private function server(string $mode): RawServer
    {
        return $this->servers[] = new RawServer($mode);
    }

    private function gd(RawServer $s, int $retries = 2, ?float $idle = 1.0, ?float $response = 30.0): GaiaDesk
    {
        return new GaiaDesk(
            apiKey: 'ak_t',
            deskToken: 'gdagt_t',
            baseUrl: $s->url,
            e2e: 'off',
            maxRetries: $retries,
            responseTimeout: $response,
            idleTimeout: $idle,
            sleep: static function (float $s): void {
                usleep(5000);
            },
        );
    }

    /**
     * Run $f, failing the test if it takes longer than BOUND seconds (where SIGALRM exists).
     *
     * @template T
     *
     * @param callable(): T $f
     *
     * @return T
     */
    private function bounded(callable $f): mixed
    {
        if (!\function_exists('pcntl_alarm')) {
            return $f();
        }
        $this->hung = false;
        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, function (): void {
            $this->hung = true;
            throw new AssertionFailedError('no answer within '.self::BOUND.' s: the SDK hung');
        }, false);
        pcntl_alarm(self::BOUND);
        try {
            return $f();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            self::assertFalse($this->hung, 'no answer within '.self::BOUND.' s: the SDK hung');
        }
    }

    /**
     * @template E of \Throwable
     *
     * @param class-string<E> $class
     * @param callable(): mixed $f
     *
     * @return array{E, float} the error and how long it took
     */
    private function fails(string $class, callable $f): array
    {
        $t0 = microtime(true);
        try {
            $this->bounded($f);
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());

            return [$e, microtime(true) - $t0];
        }
        self::fail("no $class");
    }

    /** @return iterable<string, array{string}> */
    public static function droppedForReads(): iterable
    {
        yield 'closed' => ['close'];
        yield 'reset' => ['reset'];
    }

    /** @return iterable<string, array{string}> */
    public static function droppedForWrites(): iterable
    {
        yield 'closed' => ['close'];
        yield 'reset' => ['reset'];
        yield 'closed after the body' => ['close-body'];
    }

    #[DataProvider('droppedForReads')]
    public function testDroppedBeforeAnyResponseByteAReadIsRetriedThenUnreachableNetwork(string $mode): void
    {
        $s = $this->server($mode);
        $gd = $this->gd($s);
        [$e] = $this->fails(UnreachableException::class, static fn () => $gd->downloadBytes(self::D, '/tmp/x'));
        self::assertSame(['network', 'network', 255], [$e->getKind(), $e->getReason(), $e->getExitCode()]);
        self::assertSame(['GET /desks/123456789/files'], $e->getArgv());
        // The first try and the SDK's two retries: a GET is safe to send again. The SDK's curl client
        // opens a connection per request, so libcurl never re-sends one by itself (pinned below).
        self::assertSame(3, $s->count('GET'));
        $this->fails(UnreachableException::class, static fn () => $gd->stats(self::D));
        self::assertSame(6, $s->count('GET'));
        $once = $this->gd($s, retries: 0);
        $this->fails(UnreachableException::class, static fn () => $once->stats(self::D));
        self::assertSame(7, $s->count('GET'));
    }

    #[DataProvider('droppedForWrites')]
    public function testDroppedBeforeAnyResponseByteAnUploadOrAnExecIsNeverSentTwice(string $mode): void
    {
        $s = $this->server($mode);
        $gd = $this->gd($s);
        $big = random_bytes(4 * 1024 * 1024);
        [$e] = $this->fails(UnreachableException::class, static fn () => $gd->uploadFrom($big, self::D, '/tmp/big'));
        self::assertSame('network', $e->getKind());
        self::assertSame(1, $s->count('PUT'));
        $file = tempnam(sys_get_temp_dir(), 'gdraw');
        self::assertIsString($file);
        try {
            file_put_contents($file, $big);
            $this->fails(UnreachableException::class, static fn () => $gd->upload($file, self::D, '/tmp/streamed'));
            self::assertSame(2, $s->count('PUT'));
        } finally {
            unlink($file);
        }
        $this->fails(UnreachableException::class, static fn () => $gd->exec(self::D, 'deploy'));
        self::assertSame(1, $s->count('POST'));
        $exit = $this->bounded(static fn () => $gd->execStream(self::D, 'deploy')->wait());
        self::assertSame(['unreachable', 'network', 255], [$exit->error['kind'] ?? null, $exit->error['reason'] ?? null, $exit->exitCode]);
        self::assertSame(2, $s->count('POST'));
        $this->fails(UnreachableException::class, static fn () => $gd->runJob(self::D, 'nightly', 'make'));
        self::assertSame(3, $s->count('POST'));
        // An idempotency key does not make the SDK resend a call that may have run.
        $this->fails(UnreachableException::class, static fn () => $gd->exec(self::D, 'deploy', idempotencyKey: 'deploy-1'));
        self::assertSame(4, $s->count('POST'));
        self::assertSame(0, $s->count('GET'));
    }

    public function testStalledMidDownloadIsConnectionLostTimeoutWithinTheIdleTimeoutAndLeavesNoFile(): void
    {
        $s = $this->server('stall-body');
        $gd = $this->gd($s, idle: 1.0);
        $sink = fopen('php://memory', 'w+b');
        self::assertIsResource($sink);
        [$e, $took] = $this->fails(ConnectionLostException::class, static fn () => $gd->downloadTo(self::D, '/tmp/x', $sink));
        rewind($sink);
        self::assertSame('hello', stream_get_contents($sink), 'what arrived was handed over as it arrived');
        self::assertSame(['timeout', 'timeout', 255], [$e->getKind(), $e->getReason(), $e->getExitCode()]);
        self::assertStringContainsString('idleTimeout', $e->getMessage());
        self::assertLessThan(5.0, $took);
        $dir = sys_get_temp_dir().'/gd-stall-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->fails(ConnectionLostException::class, static fn () => $gd->download(self::D, '/tmp/x', "$dir/x.bin"));
            self::assertSame([], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'no file, not even a partial one');
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
        self::assertSame(2, $s->count('GET'), 'an answer that had begun is not retried');
    }

    public function testStalledMidJsonIsConnectionLostTimeout(): void
    {
        $s = $this->server('stall-json');
        $gd = $this->gd($s, retries: 0);
        [$e, $took] = $this->fails(ConnectionLostException::class, static fn () => $gd->stats(self::D));
        self::assertSame(['timeout', 'timeout'], [$e->getKind(), $e->getReason()]);
        self::assertStringContainsString('idleTimeout', $e->getMessage());
        self::assertLessThan(5.0, $took);
        $retrying = $this->gd($s);
        $this->fails(ConnectionLostException::class, static fn () => $retrying->stats(self::D));
        self::assertSame(2, $s->count('GET'), 'an idle timeout mid-answer is not retried');
    }

    public function testStalledMidStreamEndsTheStreamWithATimeoutError(): void
    {
        $s = $this->server('stall-events');
        $gd = $this->gd($s);
        [$out, $exit] = $this->bounded(static function () use ($gd): array {
            $stream = $gd->execStream(self::D, 'tail -f log');
            $out = '';
            foreach ($stream as $c) {
                $out .= $c->data;
            }

            return [$out, $stream->wait()];
        });
        self::assertSame('hi', $out);
        self::assertSame(['connection_lost', 'timeout', 255], [$exit->error['kind'] ?? null, $exit->error['reason'] ?? null, $exit->exitCode]);
        self::assertStringContainsString('idleTimeout', $exit->error['message'] ?? '');
        $logs = $this->bounded(static fn () => $gd->followJobLogs(self::D, 'build')->wait());
        self::assertSame('connection_lost', $logs->error['kind'] ?? null);
    }

    public function testASilentServerIsUnreachableTimeoutWithinTheResponseTimeoutAndNotRetried(): void
    {
        $s = $this->server('silent');
        $gd = $this->gd($s, response: 1.0);
        [$e, $took] = $this->fails(UnreachableException::class, static fn () => $gd->stats(self::D));
        self::assertSame(['timeout', 'timeout', 255], [$e->getKind(), $e->getReason(), $e->getExitCode()]);
        self::assertStringContainsString('responseTimeout', $e->getMessage());
        self::assertStringContainsString('GET /desks/123456789/stats', $e->getMessage());
        self::assertLessThan(5.0, $took);
        // A large upload the server never reads: sending it counts against the same limit.
        [, $took] = $this->fails(UnreachableException::class, static fn () => $gd->uploadFrom(random_bytes(4 * 1024 * 1024), self::D, '/tmp/big'));
        self::assertLessThan(5.0, $took);
        self::assertSame(1, $s->count('GET'));
        self::assertSame(1, $s->count('PUT'));
    }

    public function testStress300DroppedRequestsNeverHang(): void
    {
        $s = $this->server('close');
        $gd = $this->gd($s, retries: 1);
        $up = random_bytes(512 * 1024);
        $modes = ['close', 'reset', 'close-body'];
        $fds = self::openFiles();
        for ($i = 0; $i < 300; ++$i) {
            $s->mode($modes[$i % 3]);
            $op = 0 === $i % 2 ? static fn () => $gd->downloadBytes(self::D, '/tmp/x') : static fn () => $gd->uploadFrom($up, self::D, '/tmp/up');
            [$e] = $this->fails(UnreachableException::class, $op);
            self::assertSame('network', $e->getKind(), "iteration $i ({$modes[$i % 3]})");
        }
        if (null !== $fds) {
            // Every failed request's connection closed as it failed, not when a garbage collection gets to it.
            self::assertLessThan($fds + 20, self::openFiles());
        }
        self::assertSame(150, $s->count('PUT'), 'every upload sent exactly once');
        self::assertSame(300, $s->count('GET'), 'every read tried twice, and never re-sent by libcurl');
    }

    /** How many files (sockets included) this process has open, where the system says. */
    private static function openFiles(): ?int
    {
        foreach (['/proc/self/fd', '/dev/fd'] as $dir) {
            $list = @scandir($dir);
            if (\is_array($list)) {
                return \count($list);
            }
        }

        return null;
    }

    public function testTimeoutsAreChecked(): void
    {
        $bad = [
            'idleTimeout' => static fn () => new GaiaDesk(apiKey: 'ak', idleTimeout: 0.0),
            'responseTimeout' => static fn () => new GaiaDesk(apiKey: 'ak', responseTimeout: -2.0),
            'idleTimeout ' => static fn () => new GaiaDesk(apiKey: 'ak', idleTimeout: \INF),
            'responseTimeout ' => static fn () => new GaiaDesk(apiKey: 'ak', responseTimeout: \NAN),
        ];
        foreach ($bad as $name => $make) {
            try {
                $make();
                self::fail("accepted a bad $name");
            } catch (UsageException $e) {
                self::assertStringContainsString(trim($name), $e->getMessage());
                self::assertSame('usage', $e->getKind());
            }
        }
        // null: no limit.
        self::assertSame('api', (new GaiaDesk(apiKey: 'ak', responseTimeout: null, idleTimeout: null))->transport());
        $this->expectException(UsageException::class);
        GaiaDesk::lan('https://127.0.0.1:7443/v1', str_repeat('ab', 32), 'gdagt_t', idleTimeout: 0.0);
    }

    /**
     * libcurl re-sends a request (any method, its body rewound) when the connection it
     * REUSED was closed before any answer. The SDK's client opens a connection per request,
     * and forces a fresh, unshared one for everything but GET and HEAD, even when the caller
     * hands it a connection cache (CURLOPT_SHARE): a PUT reaches the server once.
     */
    public function testCurlNeverReSendsARequestWithABodyOnAReusedConnection(): void
    {
        if (!\defined('CURL_LOCK_DATA_CONNECT')) {
            self::markTestSkipped('this libcurl cannot share connections');
        }
        $s = $this->server('keepalive');
        $share = curl_share_init();
        curl_share_setopt($share, \CURLSHOPT_SHARE, \CURL_LOCK_DATA_CONNECT);
        $curl = new CurlClient(curlOptions: [\CURLOPT_SHARE => $share]);
        $opts = new RequestOptions(responseTimeout: 5.0, idleTimeout: 5.0);
        // A first request leaves its connection in the shared cache; the server will drop the next one on it.
        self::assertSame('{}', (string) $this->bounded(static fn () => $curl->sendWith(new Request('GET', "http://{$s->address}/v1/a"), $opts))->getBody());
        try {
            $this->bounded(static fn () => $curl->sendWith(new Request('PUT', "http://{$s->address}/v1/desks/1/files?path=x", ['Content-Type' => 'application/octet-stream'], str_repeat('x', 4096)), $opts));
        } catch (NetworkException) {
            // Refused on the dropped connection: fine, as long as it was not sent again.
        }
        self::assertSame(1, $s->count('PUT'), 'the PUT reached the server exactly once');
        // A GET may reuse the shared connection (and libcurl may re-send it): reads are safe to repeat.
        self::assertSame('{}', (string) $this->bounded(static fn () => $curl->sendWith(new Request('GET', "http://{$s->address}/v1/b"), $opts))->getBody());
    }

    /** The named-pipe and LAN road (raw HTTP/1.1 on a stream) is bounded the same way. */
    public function testRawHttpOnAStreamIsBoundedToo(): void
    {
        $s = $this->server('silent');
        $addr = $s->address;
        $client = new SocketClient(static function () use ($addr) {
            $c = stream_socket_client("tcp://$addr");
            self::assertIsResource($c);

            return $c;
        });
        $opts = new RequestOptions(responseTimeout: 1.0, idleTimeout: 1.0);
        [$e, $took] = $this->fails(NetworkException::class, static fn () => $client->sendWith(new Request('GET', 'http://x/v1/desks/1/stats'), $opts));
        self::assertSame([true, false, 'responseTimeout'], [$e->timedOut, $e->answerBegun, $e->limit], $e->getMessage());
        self::assertLessThan(5.0, $took);
        // Writing a body the server never reads.
        [$e, $took] = $this->fails(NetworkException::class, static fn () => $client->sendWith(new Request('PUT', 'http://x/v1/desks/1/files?path=x', [], random_bytes(8 * 1024 * 1024)), $opts));
        self::assertSame([true, 'responseTimeout'], [$e->timedOut, $e->limit]);
        self::assertLessThan(5.0, $took);
        $s->mode('stall-json');
        [$e, $took] = $this->fails(NetworkException::class, static fn () => $client->sendWith(new Request('GET', 'http://x/v1/desks/1/stats'), $opts));
        self::assertSame([true, true, 'idleTimeout'], [$e->timedOut, $e->answerBegun, $e->limit]);
        self::assertLessThan(5.0, $took);
        $s->mode('stall-body');
        $res = $this->bounded(static fn () => $client->sendWith(new Request('GET', 'http://x/v1/desks/1/files'), new RequestOptions(responseTimeout: 1.0, idleTimeout: 1.0, stream: true)));
        $body = $res->getBody();
        self::assertSame('hello', $this->bounded(static fn () => $body->read(100)));
        [$e] = $this->fails(NetworkException::class, static fn () => $body->read(100));
        self::assertSame([true, true, 'idleTimeout'], [$e->timedOut, $e->answerBegun, $e->limit]);
        $s->mode('reset');
        [$e] = $this->fails(NetworkException::class, static fn () => $client->sendWith(new Request('GET', 'http://x/v1/desks/1/stats'), $opts));
        self::assertSame([false, false], [$e->timedOut, $e->answerBegun]);
    }

    /**
     * A plain PSR-18 client owns its own timeouts, but a body it hands over as a PHP stream
     * is read by the SDK under idleTimeout.
     */
    public function testAPlainPsr18ClientsBodyIsReadUnderTheIdleTimeout(): void
    {
        $s = $this->server('stall-json');
        $client = new class($s->address) implements ClientInterface {
            public function __construct(private readonly string $address)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $c = stream_socket_client("tcp://{$this->address}");
                if (false === $c) {
                    throw new \RuntimeException('no server');
                }
                fwrite($c, $request->getMethod().' '.$request->getRequestTarget()." HTTP/1.1\r\nHost: x\r\n\r\n");
                $head = '';
                while (!str_contains($head, "\r\n\r\n")) {
                    $head .= (string) fread($c, 1);
                }

                return new Response(200, ['Content-Type' => 'application/json'], Stream::create($c));
            }
        };
        $gd = new GaiaDesk(apiKey: 'ak_t', baseUrl: $s->url, e2e: 'off', httpClient: $client, maxRetries: 0, idleTimeout: 1.0);
        [$e, $took] = $this->fails(ConnectionLostException::class, static fn () => $gd->stats(self::D));
        self::assertSame(['timeout', 'timeout'], [$e->getKind(), $e->getReason()]);
        self::assertLessThan(5.0, $took);
    }

    public function testErrorsCarryTheirKindOnEveryRoad(): void
    {
        // A GaiaDeskException's kind decides retries; make sure a stall is never "network".
        $s = $this->server('stall-json');
        $gd = $this->gd($s, retries: 2);
        [$e] = $this->fails(GaiaDeskException::class, static fn () => $gd->desk(self::D));
        self::assertInstanceOf(ConnectionLostException::class, $e);
        self::assertSame(1, $s->count('GET'));
    }
}
