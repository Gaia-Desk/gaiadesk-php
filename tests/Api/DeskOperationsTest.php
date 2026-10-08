<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Api;

use GaiaDesk\Exception\CommandException;
use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\OperationFailedException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Tests\Support\FakeApi;
use GaiaDesk\Tests\Support\MockClient;
use Nyholm\Psr7\Stream;

/** Every desk operation in the clear, over a mock PSR-18 client: what is sent, what comes back, and the errors. */
final class DeskOperationsTest extends ApiTestCase
{
    public function testChoosingTheTransport(): void
    {
        self::assertSame('api', $this->gd()->transport());
        $this->throws(UsageException::class, static fn () => new GaiaDesk());
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: ' '));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: 'ak_x', baseUrl: 'ftp://x'));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: 'ak_x', fingerprint: 'ab'));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: 'ak_x', transport: 'carrier-pigeon'));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: 'ak_x', e2e: 'always'));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(apiKey: 'ak_x', e2eKeys: ['1' => 'short']));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(transport: 'local', apiKey: 'ak_x'));
        $this->throws(UsageException::class, static fn () => new GaiaDesk(transport: 'local', e2e: 'require'));
        $this->throws(UsageException::class, static fn () => GaiaDesk::lan('http://x:7443/v1', str_repeat('ab', 32)));
        self::assertSame([], $this->api->requests);
    }

    public function testCredentialsTokensAndWake(): void
    {
        $gd = $this->gd();
        $gd->stats(self::OK);
        self::assertSame('Bearer ak_test', $this->last()->header('authorization'));
        self::assertSame('gdagt_test', $this->last()->header('x-gaiadesk-desk-token'));
        self::assertSame('/v1/desks/123456789/stats', $this->last()->path);
        self::assertStringStartsWith('gaiadesk-php/'.GaiaDesk::VERSION, (string) $this->last()->header('user-agent'));
        $gd->stats(self::OK, deskToken: 'gdagt_other', wake: 30);
        self::assertSame('gdagt_other', $this->last()->header('x-gaiadesk-desk-token'));
        self::assertSame(['wake_s' => '30'], $this->last()->query);
        $this->throws(UsageException::class, static fn () => $gd->stats(self::OK, wake: 121));
        $this->gd(['apiKey' => 'session-person', 'deskToken' => null])->listTokens(self::OK);
        self::assertNull($this->last()->header('x-gaiadesk-desk-token'), 'no desk token unless one is given');
        self::assertCount(1, $this->warnings, 'warned once that the desk is not end-to-end encrypted');
        self::assertStringContainsString('not end-to-end encrypted', $this->warnings[0]);
    }

    public function testExecSendsAnExecSpec(): void
    {
        $gd = $this->gd();
        $r = $gd->exec(self::OK, 'hostname', shell: 'sh', timeout: '10m', cwd: '/srv', stdin: 'in', env: ['STAGE' => 'prod', 'EMPTY' => '']);
        self::assertSame('POST', $this->last()->method);
        self::assertSame('application/json', $this->last()->header('content-type'));
        self::assertSame(['command' => 'hostname', 'shell' => 'sh', 'env' => ['STAGE' => 'prod', 'EMPTY' => ''], 'cwd' => '/srv', 'timeout_secs' => 600, 'stdin' => 'in'], $this->body());
        self::assertSame(0, $r['exit']);
        self::assertSame("ran: hostname é\nenv: STAGE=prod\nenv: EMPTY=\nstdin: in\n", $r['stdout']);
        self::assertNull($r['error'] ?? null);
        $gd->exec(self::OK, ['ls', '-l'], shell: 'powershell', env: []);
        self::assertSame(['argv' => ['ls', '-l'], 'shell' => 'pwsh', 'env' => []], $this->body());
        self::assertSame('{"argv":["ls","-l"],"shell":"pwsh","env":{}}', $this->last()->body, 'an empty env is an object');
        $gd->exec(self::OK, 'deploy', idempotencyKey: 'deploy-1');
        self::assertSame('deploy-1', $this->last()->header('idempotency-key'));
        $n = \count($this->api->requests);
        $this->throws(UsageException::class, static fn () => $gd->exec(self::OK, 'x', env: ['A=B' => 'v']));
        $this->throws(UsageException::class, static fn () => $gd->exec(self::OK, ''));
        $this->throws(UsageException::class, static fn () => $gd->exec(self::OK, 'x', shell: 'fish'));
        $this->throws(UsageException::class, static fn () => $gd->exec(self::OK, 'x', idempotencyKey: str_repeat('k', 256)));
        self::assertCount($n, $this->api->requests, 'nothing sent for a bad call');
    }

    public function testExecCheckAndRefusals(): void
    {
        $gd = $this->gd();
        self::assertSame(3, $gd->exec(self::OK, 'fail')['exit'], 'a non-zero exit is a result');
        $e = $this->throws(CommandException::class, static fn () => $gd->exec(self::OK, 'fail', check: true));
        self::assertSame(3, $e->getExitCode());
        self::assertSame(3, $e->getResult()['exit']);
        // The desk refused it: 403 with the envelope.
        $e = $this->throws(RefusedException::class, static fn () => $gd->exec(self::OK, 'refuse'));
        self::assertSame(['refused', 'token_refused', 403, 254, self::OK], [$e->getKind(), $e->getReason(), $e->getStatus(), $e->getExitCode(), $e->getDesk()]);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/', (string) $e->getRequestId());
    }

    public function testAdminExec(): void
    {
        $gd = $this->gd();
        $r = $gd->exec(self::OK, 'whoami', admin: true);
        self::assertTrue($this->body()['admin']);
        self::assertStringContainsString('as: root', $r['stdout']);
        foreach (['admin_not_enabled', 'admin_denied', 'admin_scope_missing', 'admin_unavailable'] as $reason) {
            // A refusal answers 200 with exit 254 and error.reason: the command never ran.
            $e = $this->throws(RefusedException::class, static fn () => $gd->exec(self::OK, $reason, admin: true));
            self::assertSame([$reason, 254, 'refused', null], [$e->getReason(), $e->getExitCode(), $e->getKind(), $e->getStatus()]);
        }
        $gd->exec(self::OK, 'x');
        self::assertArrayNotHasKey('admin', $this->body(), 'admin only when asked');
    }

    public function testExecStream(): void
    {
        $gd = $this->gd();
        $s = $gd->execStream(self::OK, 'build', env: ['A' => '1'], stdin: 'in');
        self::assertSame(['stream' => '1'], $this->last()->query);
        self::assertSame('text/event-stream', $this->last()->header('accept'));
        self::assertSame(['command' => 'build', 'env' => ['A' => '1'], 'stdin' => 'in'], $this->body());
        $chunks = [];
        foreach ($s as $c) {
            $chunks[] = [$c->stream, $c->data];
        }
        self::assertSame([['stdout', 'ran: build '], ['stderr', "warn\n"], ['stdout', "é\nenv: A=1\nstdin: in\n"]], $chunks, 'the split character is carried whole');
        $exit = $s->wait();
        self::assertSame(0, $exit->exitCode);
        self::assertTrue($exit->ok());
        self::assertSame(0, $exit->result['exit'] ?? null);
        self::assertArrayNotHasKey('stdout', $exit->result ?? []);
        self::assertTrue($this->http->options[\count($this->http->options) - 1]->stream);
        $this->throws(UsageException::class, static fn () => $s->write('x'));
        $this->throws(UsageException::class, static fn () => iterator_to_array($s));
    }

    public function testStreamsArriveAsTheyAreRead(): void
    {
        $s = $this->gd()->execStream(self::OK, 'slow');
        $body = $this->http->bodies[0];
        foreach ($s as $c) {
            break;
        }
        $read = $body->reads;
        self::assertLessThan(20, $read, 'the first chunk came before the rest was read');
        $s->cancel();
        self::assertTrue($body->closed, 'cancel hangs up');
        self::assertSame(130, $s->wait()->exitCode);
    }

    public function testStreamEndings(): void
    {
        $gd = $this->gd();
        // Refused before it starts: no output, the typed error as the exit.
        $s = $this->gd(['deskToken' => null])->execStream(self::OK, 'x');
        self::assertSame([], iterator_to_array($s));
        $exit = $s->wait();
        self::assertSame([254, 'refused', 'desk_token_required'], [$exit->exitCode, $exit->error['kind'] ?? null, $exit->error['reason'] ?? null]);
        // The desk went away mid-stream.
        $lost = self::drain($gd->execStream(self::OK, 'lose'));
        self::assertSame("ran: lose é\n", $lost['out']);
        self::assertSame(['connection_lost', 255], [$lost['exit']->error['kind'] ?? null, $lost['exit']->exitCode]);
        // A stream that breaks off after it began: the connection was lost.
        $this->http->failNext = 0;
        $s = $gd->execStream(self::OK, 'slow');
        $this->http->bodies[\count($this->http->bodies) - 1]->breakAfter = 4;
        $exit = $s->wait();
        self::assertSame([255, 'connection_lost', 'incomplete'], [$exit->exitCode, $exit->error['kind'] ?? null, $exit->error['reason'] ?? null]);
        // No connection at all.
        $this->http->failNext = 1;
        $exit = $gd->execStream(self::OK, 'x')->wait();
        self::assertSame([255, 'unreachable', 'network'], [$exit->exitCode, $exit->error['kind'] ?? null, $exit->error['reason'] ?? null]);
    }

    public function testJobs(): void
    {
        $gd = $this->gd();
        $j = $gd->runJob(self::OK, 'build', 'make all', priority: 'low', cpu: 50, mem: '2G', keepAwake: true, cwd: 'src', shell: 'powershell', env: ['CI' => '1'], idempotencyKey: 'job-1');
        self::assertSame(['name' => 'build', 'command' => ['make all'], 'limits' => ['priority' => 'low', 'cpu_percent' => 50, 'mem_mb' => 2048, 'keep_awake' => true], 'cwd' => 'src', 'shell' => 'pwsh', 'env' => ['CI' => '1']], $this->body());
        self::assertSame(['running', 'job-1'], [$j['state'], $this->last()->header('idempotency-key')]);
        $gd->runJob(self::OK, 'b', ['./x', '--y']);
        self::assertSame('{"name":"b","command":["./x","--y"],"limits":{}}', $this->last()->body, 'empty limits are an object');
        self::assertSame('build', $gd->jobs(self::OK)[0]['name']);
        self::assertSame('killed', $gd->killJob(self::OK, 'build')['state']);
        self::assertSame(['DELETE', '/v1/desks/123456789/jobs/build'], [$this->last()->method, $this->last()->path]);
        self::assertSame("tail 10\n", $gd->jobLogs(self::OK, 'build', tail: 10));
        self::assertSame(['tail' => '10'], $this->last()->query);
        $f = self::drain($gd->followJobLogs(self::OK, 'build'));
        self::assertSame("line1\nline2 é\n", $f['out']);
        self::assertSame(0, $f['exit']->exitCode);
        self::assertSame('job build exited (exit 0)', $f['exit']->stderrTail);
        self::assertSame(['follow' => '1'], $this->last()->query);
        $m = self::drain($gd->followJobLogs(self::OK, 'missing'));
        self::assertSame(['failed', 1, 'no job named "missing"'], [$m['exit']->error['kind'] ?? null, $m['exit']->exitCode, $m['exit']->error['message'] ?? null]);
        $this->throws(OperationFailedException::class, static fn () => $gd->jobLogs(self::OK, 'missing'));
        $this->throws(UsageException::class, static fn () => $gd->runJob(self::OK, 'b', 'x', shell: 'none'));
        $this->throws(UsageException::class, static fn () => $gd->runJob(self::OK, 'b', 'x', cpu: 101));
        $this->throws(UsageException::class, static fn () => $gd->runJob(self::OK, 'b', 'x', priority: 'urgent'));
        $this->throws(UsageException::class, static fn () => $gd->killJob(self::OK, '-x'));
    }

    public function testWaitJob(): void
    {
        $gd = $this->gd();
        $done = $gd->waitJob(self::OK, 'failing', timeout: '10m');
        self::assertSame(['GET', '/v1/desks/123456789/jobs/failing/wait', ['timeout' => '600']], [$this->last()->method, $this->last()->path, $this->last()->query]);
        self::assertSame([false, 'exited', 3], [$done['timed_out'], $done['job']['state'], $done['job']['exit_code'] ?? null]);
        $now = $gd->waitJob(self::OK, 'slow', timeout: 0);
        self::assertSame([true, '0'], [$now['timed_out'], $this->last()->query['timeout']]);
        $gd->waitJob(self::OK, 'build');
        self::assertSame('870', $this->last()->query['timeout'], "no timeout: the API's longest");
        self::assertSame('held', $gd->waitJob(self::OK, 'held')['job']['name'], 'leading keep-alive spaces are still JSON');
        $e = $this->throws(ConnectionLostException::class, static fn () => $gd->waitJob(self::OK, 'held-fail'));
        self::assertSame(['connection_lost', 'desk_disconnected', 502], [$e->getKind(), $e->getReason(), $e->getStatus()]);
        $e = $this->throws(OperationFailedException::class, static fn () => $gd->waitJob(self::OK, 'held-gone'));
        self::assertSame(422, $e->getStatus(), "a held envelope's status");
        $this->throws(OperationFailedException::class, static fn () => $gd->waitJob(self::OK, 'nope'));
        $before = \count($this->api->requests);
        self::assertTrue($gd->waitJob(self::OK, 'slow', timeout: 0.3)['timed_out']);
        self::assertSame('1', $this->last()->query['timeout'], 'a short timeout is asked as is');
        self::assertGreaterThanOrEqual(1, \count($this->api->requests) - $before);
    }

    public function testFiles(): void
    {
        $gd = $this->gd();
        $r = $gd->uploadFrom('hello', self::OK, 'notes/a.txt');
        self::assertSame(['PUT', ['path' => 'notes/a.txt'], 'application/octet-stream', 'hello'], [$this->last()->method, $this->last()->query, $this->last()->header('content-type'), $this->last()->body]);
        self::assertSame(['upload', 5], [$r['direction'], $r['bytes']]);
        self::assertSame('hello', $gd->downloadBytes(self::OK, 'notes/a.txt'));
        $h = fopen('php://memory', 'w+b');
        self::assertIsResource($h);
        fwrite($h, 'from a resource');
        rewind($h);
        $gd->uploadFrom($h, self::OK, 'r.txt');
        self::assertSame('from a resource', $this->api->files['r.txt']);
        $gd->uploadFrom(Stream::create('from a psr-7 stream'), self::OK, 's.txt');
        self::assertSame('from a psr-7 stream', $this->api->files['s.txt']);

        $dir = sys_get_temp_dir().'/gaiadesk-php-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/report.csv", "a,b\n1,2\n");
        $gd->upload("$dir/report.csv", self::OK, 'inbox/');
        self::assertSame(['path' => 'inbox/report.csv'], $this->last()->query, 'a folder keeps the name');
        $d = $gd->download(self::OK, 'inbox/report.csv', "$dir/");
        self::assertSame(["$dir/report.csv", 8, 'download'], [$d['destination'], $d['bytes'], $d['direction']]);
        self::assertSame("a,b\n1,2\n", file_get_contents("$dir/report.csv"));
        $sink = fopen('php://memory', 'w+b');
        self::assertIsResource($sink);
        self::assertSame(8, $gd->downloadTo(self::OK, 'inbox/report.csv', $sink));
        self::assertTrue($this->http->options[\count($this->http->options) - 1]->stream, 'downloads are read as they arrive');
        $e = $this->throws(OperationFailedException::class, static fn () => $gd->download(self::OK, 'missing', "$dir/m"));
        self::assertSame('not_found', $e->getReason());
        self::assertFalse(file_exists("$dir/m"), 'no file for a failed download');
        self::assertSame(['report.csv'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'no partial file left');
        $this->throws(OperationFailedException::class, static fn () => $gd->uploadFrom('x', self::OK, 'fails'));
        $this->throws(UsageException::class, static fn () => $gd->upload($dir, self::OK, 'x/'));
        $this->throws(GaiaDeskException::class, static fn () => $gd->upload("$dir/none", self::OK, 'x'));
        $this->throws(UsageException::class, static fn () => $gd->uploadFrom('x', self::OK, ''));
        unlink("$dir/report.csv");
        rmdir($dir);
    }

    public function testTokens(): void
    {
        $owner = $this->gd(['apiKey' => 'session-person', 'deskToken' => null]);
        $m = $owner->createToken(self::OK, 'bot', expires: '24h', cwd: '/srv', lowPriv: true);
        self::assertSame(['name' => 'bot', 'expires_secs' => 86400, 'scopes' => ['exec', 'cp', 'jobs'], 'cwd' => '/srv', 'low_priv' => true], $this->body());
        self::assertSame('gdagt_minted_secret', $m['tokens'][0]['secret']);
        $owner->createToken(self::OK, 'admin-bot', scopes: ['exec', 'admin']);
        self::assertSame(['exec', 'admin'], $this->body()['scopes'], 'admin only when named');
        $this->throws(UsageException::class, static fn () => $owner->createToken(self::OK, 'x', scopes: ['admin'], cwd: '/srv'));
        $this->throws(UsageException::class, static fn () => $owner->createToken(self::OK, 'x', scopes: []));
        $this->throws(UsageException::class, static fn () => $owner->createToken([], 'x'));
        self::assertSame('tok1', $owner->listTokens(self::OK)[0]['id']);
        self::assertSame('9f3a1c2b7d004e11', $owner->revokeToken(self::OK, '9f3a1c2b7d004e11')['revoked']);
        self::assertSame(['DELETE', '/v1/desks/123456789/tokens/9f3a1c2b7d004e11'], [$this->last()->method, $this->last()->path]);
        // Several desks: one token each; a failure later keeps the ones minted.
        $e = $this->throws(UnreachableException::class, static fn () => $owner->createToken([self::OK, '000000000'], 'bot'));
        $json = $e->getJson();
        self::assertIsArray($json);
        self::assertCount(1, $json['tokens']);
        // From an API key: the owner's only.
        $e = $this->throws(RefusedException::class, fn () => $this->gd()->listTokens(self::OK));
        self::assertSame('session_required', $e->getReason());
    }

    public function testErrorEnvelopes(): void
    {
        $gd = $this->gd(['maxRetries' => 0, 'e2e' => 'off']);
        $e = $this->throws(UnreachableException::class, static fn () => $gd->exec(FakeApi::OFFLINE, 'x'));
        self::assertSame([409, 'offline', 'offline', FakeApi::OFFLINE], [$e->getStatus(), $e->getKind(), $e->getReason(), $e->getDesk()]);
        $e = $this->throws(UsageException::class, static fn () => $gd->exec(FakeApi::USAGE, 'x'));
        self::assertSame([400, 'usage'], [$e->getStatus(), $e->getKind()]);
        $e = $this->throws(UnreachableException::class, static fn () => $gd->stats(FakeApi::TIMEOUT));
        self::assertSame([504, 'timeout'], [$e->getStatus(), $e->getKind()]);
        $e = $this->throws(RefusedException::class, static fn () => $gd->stats(FakeApi::LIMITED));
        self::assertSame([429, 'rate_limited', 7.0], [$e->getStatus(), $e->getReason(), $e->getRetryAfter()]);
        $e = $this->throws(ProtocolException::class, static fn () => $gd->stats(FakeApi::HTML));
        self::assertSame([500, 'protocol'], [$e->getStatus(), $e->getKind()]);
        self::assertStringContainsString('no error envelope', $e->getMessage());
        self::assertStringContainsString('Internal Server Error', $e->getBody());
        $e = $this->throws(UnreachableException::class, static fn () => $gd->stats('000000000'));
        self::assertSame(['unknown_desk', 404], [$e->getKind(), $e->getStatus()]);
        $this->http->failNext = 1;
        $e = $this->throws(UnreachableException::class, static fn () => $gd->stats(self::OK));
        self::assertSame(['network', 'network', 255, null], [$e->getKind(), $e->getReason(), $e->getExitCode(), $e->getStatus()]);
        self::assertSame(['GET /desks/123456789/stats'], $e->getArgv());
    }

    public function testRetries(): void
    {
        $gd = $this->gd(['e2e' => 'off']);
        // 429 desk_busy: waited as Retry-After says, then done (any method).
        $this->api->busy = 2;
        self::assertSame(0, $gd->exec(FakeApi::BUSY, 'x')['exit']);
        self::assertSame([0.0, 0.0], $this->sleeps);
        // Over maxRetries: the error.
        $this->api->busy = 5;
        $this->throws(RefusedException::class, static fn () => $gd->stats(FakeApi::BUSY));
        // A Retry-After longer than a minute is not waited for.
        $this->sleeps = [];
        $this->throws(RefusedException::class, static fn () => $gd->stats(FakeApi::LIMITED));
        self::assertSame([7.0, 7.0], $this->sleeps);
        // A GET on 502: retried; a POST is not.
        $this->api->flaky = 1;
        self::assertSame('studio', $gd->stats(FakeApi::FLAKY)['hostname']);
        // A network error: a GET is retried, a POST is not, with or without an idempotency key.
        $this->http->failNext = 1;
        $gd->stats(self::OK);
        $this->http->failNext = 1;
        $this->throws(UnreachableException::class, static fn () => $gd->exec(self::OK, 'x'));
        $this->http->failNext = 1;
        $this->throws(UnreachableException::class, static fn () => $gd->exec(self::OK, 'x', idempotencyKey: 'k1'));
        // A connection never made: safe for any method.
        $this->http->failNext = 1;
        $this->http->failConnect = true;
        self::assertSame(0, $gd->exec(self::OK, 'x')['exit']);
        $this->http->failConnect = false;
        // maxRetries 0: nothing retried.
        $this->http->failNext = 1;
        $this->throws(UnreachableException::class, fn () => $this->gd(['maxRetries' => 0, 'e2e' => 'off'])->stats(self::OK));
    }

    public function testDevicesWithAPlainPsr18Client(): void
    {
        $gd = $this->gd(['httpClient' => new MockClient($this->api, false)]);
        $all = $gd->devices();
        self::assertGreaterThan(1, \count($all['devices']));
        self::assertSame([self::OK], array_column($gd->devices(self::OK)['devices'], 'desk_id'));
        // Streams work over a plain client too (the whole body at once).
        self::assertSame("ran: x é\n", self::drain($gd->execStream(self::OK, 'x'))['out']);
    }
}
