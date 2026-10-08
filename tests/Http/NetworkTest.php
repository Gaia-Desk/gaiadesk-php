<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Http;

use GaiaDesk\E2e\E2eLayer;
use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\FingerprintMismatchException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Http\CurlClient;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\SocketClient;
use GaiaDesk\Tests\Support\ChunkedStream;
use GaiaDesk\Tests\Support\Server;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * The SDK's own HTTP clients against a real server process: curl over TCP (the hosted
 * API's road), curl over a Unix socket (the local transport), raw HTTP/1.1 over a stream
 * (the Windows pipe's road), and pinned TLS (the LAN gateway).
 */
final class NetworkTest extends TestCase
{
    private const SEALED = '111111111';

    /** @var list<Server> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $s) {
            $s->stop();
        }
        E2eLayer::resetWarnings();
    }

    private function server(string $listen = 'tcp://127.0.0.1:0', string $mode = 'api', ?string $cert = null): Server
    {
        return $this->servers[] = new Server($listen, $mode, $cert);
    }

    /** @param array<string, mixed> $o */
    private function hosted(Server $s, array $o = []): GaiaDesk
    {
        return new GaiaDesk(...$o + ['apiKey' => 'ak_test', 'deskToken' => 'gdagt_test', 'baseUrl' => "http://{$s->address}/v1", 'onWarning' => static function (): void {
        }]);
    }

    public function testCurlEveryKindOfAnswerOverTheNetwork(): void
    {
        $s = $this->server();
        $gd = $this->hosted($s);
        self::assertSame("ran: uname é\n", $gd->exec('123456789', 'uname')['stdout']);
        // Sealed, over a real connection.
        $r = $gd->exec(self::SEALED, 'secret', env: ['K' => 'v']);
        self::assertSame("ran: secret é\nenv: K=v\n", $r['stdout']);
        $big = random_bytes(300 * 1024);
        self::assertSame(\strlen($big), $gd->uploadFrom($big, self::SEALED, 'big.bin')['bytes']);
        self::assertSame($big, $gd->downloadBytes(self::SEALED, 'big.bin'));
        $plain = random_bytes(1536 * 1024); // past the in-memory body: read as curl sends it
        $gd->uploadFrom($plain, '123456789', 'p.bin');
        self::assertSame($plain, $gd->downloadBytes('123456789', 'p.bin'));
        self::assertSame('held', $gd->waitJob('123456789', 'held')['job']['name']);
        $e = null;
        try {
            $this->hosted($s, ['maxRetries' => 0])->stats('999999991');
        } catch (RefusedException $e) {
        }
        self::assertNotNull($e);
        self::assertSame(7.0, $e->getRetryAfter());
    }

    public function testCurlStreamsEventsAsTheyArrive(): void
    {
        $s = $this->server();
        $gd = $this->hosted($s);
        $t0 = microtime(true);
        $stream = $gd->execStream(self::SEALED, 'slow');
        $first = null;
        $n = 0;
        foreach ($stream as $c) {
            $first ??= microtime(true) - $t0;
            ++$n;
        }
        $total = microtime(true) - $t0;
        self::assertGreaterThan(40, $n);
        self::assertLessThan($total / 2, (float) $first, 'the first output came long before the end');
        self::assertSame(0, $stream->wait()->exitCode);
        // Cancelled midway: hung up, 130.
        $stream = $gd->execStream('123456789', 'slow');
        foreach ($stream as $c) {
            $stream->cancel();
        }
        self::assertSame(130, $stream->wait()->exitCode);
        $f = $gd->followJobLogs(self::SEALED, 'build');
        $out = '';
        foreach ($f->text() as $t) {
            $out .= $t['text'];
        }
        self::assertSame("line1\nline2 é\n", $out);
    }

    public function testCurlTimeoutsAndBrokenAnswers(): void
    {
        $s = $this->server();
        $curl = new CurlClient();
        try {
            $curl->sendWith(new Request('GET', "http://{$s->address}/__sleep?s=2"), new RequestOptions(timeout: 0.3));
            self::fail('no timeout');
        } catch (NetworkException $e) {
            self::assertTrue($e->timedOut);
        }
        $res = $curl->sendWith(new Request('GET', "http://{$s->address}/__sleep?s=0.1"), new RequestOptions(timeout: 5));
        self::assertSame('{}', (string) $res->getBody());
        try {
            $curl->sendWith(new Request('GET', "http://{$s->address}/__short"), new RequestOptions());
            self::fail('a short body passed');
        } catch (NetworkException $e) {
            self::assertFalse($e->timedOut);
        }
        $res = $curl->sendWith(new Request('GET', "http://{$s->address}/__short"), new RequestOptions(stream: true));
        $this->expectException(\RuntimeException::class);
        $res->getBody()->getContents();
    }

    public function testNoServerIsUnreachable(): void
    {
        $gd = new GaiaDesk(apiKey: 'ak_test', baseUrl: 'http://127.0.0.1:1/v1', maxRetries: 0, e2e: 'off');
        try {
            $gd->stats('123456789');
            self::fail('reached');
        } catch (UnreachableException $e) {
            self::assertSame(['network', 'network', 255], [$e->getKind(), $e->getReason(), $e->getExitCode()]);
            self::assertInstanceOf(NetworkException::class, $e->getPrevious());
        }
    }

    public function testRawHttpOverAStream(): void
    {
        $s = $this->server();
        $addr = $s->address;
        $client = new SocketClient(static function () use ($addr) {
            $c = stream_socket_client("tcp://$addr");
            self::assertIsResource($c);

            return $c;
        });
        $res = $client->sendWith(new Request('GET', 'http://x/v1/desks', ['Authorization' => 'Bearer ak_test']), new RequestOptions());
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('123456789', (string) $res->getBody());
        // Chunked SSE, read as it arrives.
        $res = $client->sendWith(new Request('POST', 'http://x/v1/desks/123456789/exec?stream=1', ['Authorization' => 'Bearer s', 'Content-Type' => 'application/json'], '{"command":"slow"}'), new RequestOptions(stream: true));
        $first = $res->getBody()->read(100);
        self::assertStringContainsString('keep-alive', $first);
        self::assertStringContainsString('event: exit', $res->getBody()->getContents());
        // A body of unknown length goes chunked.
        $big = random_bytes(200000);
        $res = $client->sendWith(new Request('PUT', 'http://x/v1/desks/123456789/files?path=u.bin', ['Authorization' => 'Bearer s'], new ChunkedStream(str_split($big, 30000))), new RequestOptions());
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $res = $client->sendWith(new Request('GET', 'http://x/v1/desks/123456789/files?path=u.bin', ['Authorization' => 'Bearer s']), new RequestOptions());
        self::assertSame($big, (string) $res->getBody());
        $this->expectException(NetworkException::class);
        $client->sendWith(new Request('GET', 'http://x/__short'), new RequestOptions());
    }

    public function testLocalTransportOverAUnixSocket(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix sockets: macOS and Linux (Windows uses the named pipe)');
        }
        $dir = sys_get_temp_dir().'/gd-'.bin2hex(random_bytes(3));
        mkdir($dir);
        $this->server("unix://$dir/api.sock", 'local');
        file_put_contents("$dir/api-token", 'gdlocal_'.str_repeat('0', 64)."\n");
        try {
            $gd = GaiaDesk::local(env: ['GAIADESK_API_DIR' => $dir, 'HOME' => '/nonexistent']);
            self::assertSame('local', $gd->transport());
            self::assertSame('123456789', $gd->devices('123456789')['devices'][0]['desk_id']);
            self::assertSame("ran: hostname é\n", $gd->exec('123456789', 'hostname')['stdout']);
            self::assertSame(0, $gd->execStream('123456789', 'x')->wait()->exitCode);
            $gd->uploadFrom('local bytes', '123456789', 'l.txt');
            self::assertSame('local bytes', $gd->downloadBytes('123456789', 'l.txt'));
            // An agent token instead of the admin token.
            self::assertSame(5, GaiaDesk::local(env: ['GAIADESK_API_DIR' => $dir], deskToken: 'gdagt_x')->stats('123456789')['cpu_percent']);
            // The hosted API's own routes are not the desk's.
            try {
                $gd->desk('123456789');
                self::fail('served');
            } catch (UsageException $e) {
                self::assertStringContainsString('not available over the local transport', $e->getMessage());
            }
            // No socket: what to check, said plainly.
            try {
                GaiaDesk::local(socketPath: "$dir/none.sock", token: 'gdlocal_x')->stats('123456789');
                self::fail('reached');
            } catch (UnreachableException $e) {
                self::assertSame('local_api_unavailable', $e->getReason());
                self::assertStringContainsString('Local API on', $e->getMessage());
            }
            unlink("$dir/api-token");
            try {
                GaiaDesk::local(env: ['GAIADESK_API_DIR' => $dir])->stats('123456789');
                self::fail('no token');
            } catch (UnreachableException $e) {
                self::assertStringContainsString('no local admin token', $e->getMessage());
            }
        } finally {
            @unlink("$dir/api-token");
            @unlink("$dir/api.sock");
            @rmdir($dir);
        }
    }

    public function testLanGatewayWithAPinnedCertificate(): void
    {
        $key = @openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (false === $key) {
            self::markTestSkipped('OpenSSL cannot make a key here (no openssl.cnf?)');
        }
        $csr = openssl_csr_new(['commonName' => 'gaiadesk-123456789.local'], $key);
        self::assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $key, 30);
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);
        $pem = tempnam(sys_get_temp_dir(), 'gdcert');
        self::assertIsString($pem);
        file_put_contents($pem, $certPem.$keyPem);
        $fp = (string) openssl_x509_fingerprint($x509, 'sha256');
        try {
            $s = $this->server('tls://127.0.0.1:0', 'lan', $pem);
            $url = "https://{$s->address}/v1";
            $gd = GaiaDesk::lan($url, strtoupper($fp), deskToken: 'gdagt_x');
            self::assertSame('lan', $gd->transport());
            self::assertSame(5, $gd->stats('123456789')['cpu_percent']);
            self::assertSame("ran: x é\n", $gd->exec('123456789', 'x')['stdout']);
            $f = $gd->followJobLogs('123456789', 'build');
            self::assertSame(0, $f->wait()->exitCode);
            // Another certificate: refused before a byte of the request is written.
            try {
                GaiaDesk::lan($url, str_repeat('ab', 32), deskToken: 'gdagt_x')->exec('123456789', 'never');
                self::fail('a different certificate was accepted');
            } catch (FingerprintMismatchException $e) {
                self::assertSame(['fingerprint_mismatch', implode(':', str_split(strtolower($fp), 2))], [$e->getReason(), $e->getActual()]);
                self::assertSame(['POST /desks/123456789/exec'], $e->getArgv());
            }
            // Agent tokens only.
            try {
                GaiaDesk::lan($url, $fp)->stats('123456789');
                self::fail('no token');
            } catch (UsageException $e) {
                self::assertStringContainsString('agent token', $e->getMessage());
            }
            $seen = json_decode((string) file_get_contents("https://{$s->address}/__requests", false, stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])), true);
            self::assertIsArray($seen);
            foreach ($seen as $q) {
                self::assertIsArray($q);
                self::assertStringNotContainsString('never', (string) base64_decode((string) $q['body']), 'the mismatched request was never sent');
            }
        } finally {
            unlink($pem);
        }
    }

    public function testDownloadThatBreaksOffIsConnectionLost(): void
    {
        $s = $this->server();
        $gd = $this->hosted($s);
        $dir = sys_get_temp_dir().'/gd-'.bin2hex(random_bytes(3));
        mkdir($dir);
        try {
            $gd->download(self::SEALED, 'truncated', "$dir/t.bin");
            self::fail('an incomplete download passed');
        } catch (ConnectionLostException $e) {
            self::assertSame('incomplete', $e->getReason());
        }
        self::assertSame([], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'no file, not even a partial one');
        rmdir($dir);
    }
}
