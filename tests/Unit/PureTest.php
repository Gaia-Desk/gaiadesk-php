<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Unit;

use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\OperationFailedException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Exception\RefusedException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\Internal\Args;
use GaiaDesk\Internal\Errors;
use GaiaDesk\Stream\SseEvent;
use GaiaDesk\Stream\SseParser;
use GaiaDesk\Stream\Utf8Decoder;
use GaiaDesk\Transport\Lan;
use GaiaDesk\Transport\Local;
use GaiaDesk\Webhooks;
use PHPUnit\Framework\TestCase;

/** The SDK's pure parts: SSE parsing, UTF-8 pieces, durations and checks, errors, webhook signatures, paths. */
final class PureTest extends TestCase
{
    public function testSseParserSplitsAnywhere(): void
    {
        $text = ": keep-alive\r\nevent: stdout\r\ndata: {\"event\":\"stdout\",\"data\":\"a\"}\r\n\r\n:ping\n\nevent: x\ndata: line1\ndata: line2\n\ndata: {\"event\":\"exit\",\"exit\":0}";
        foreach ([1, 2, 3, 7, \strlen($text)] as $size) {
            $p = new SseParser();
            $got = [];
            for ($i = 0; $i < \strlen($text); $i += $size) {
                array_push($got, ...$p->feed(substr($text, $i, $size)));
            }
            array_push($got, ...$p->end());
            self::assertEquals([
                new SseEvent('stdout', '{"event":"stdout","data":"a"}'),
                new SseEvent('x', "line1\nline2"),
                new SseEvent('message', '{"event":"exit","exit":0}'),
            ], $got, "chunks of $size");
        }
        $p = new SseParser();
        self::assertSame([], $p->feed("data: a\r"));
        self::assertSame([], $p->feed("\ndata:b\r\r"), 'a trailing \\r may be half of \\r\\n');
        self::assertEquals([new SseEvent('message', "a\nb")], $p->feed("\n"));
    }

    public function testUtf8DecoderHoldsSplitCharacters(): void
    {
        $s = 'aé€😀z';
        for ($cut = 0; $cut <= \strlen($s); ++$cut) {
            $d = new Utf8Decoder();
            $out = $d->decode(substr($s, 0, $cut)).$d->decode(substr($s, $cut)).$d->decode('', true);
            self::assertSame($s, $out, "cut at $cut");
        }
        self::assertSame("a\u{FFFD}", (new Utf8Decoder())->decode("a\xff", true));
        $d = new Utf8Decoder();
        self::assertSame('', $d->decode("\xe2\x82"));
        self::assertSame("\u{FFFD}", $d->decode('', true), 'an unfinished character at the end');
    }

    public function testSecondsAndChecks(): void
    {
        self::assertSame([90, 2, 30, 600, 5400, 604800, 1209600, 45], [Args::seconds(90, 't'), Args::seconds(1.2, 't'), Args::seconds('30s', 't'), Args::seconds('10m', 't'), Args::seconds('1h30m', 't'), Args::seconds('7d', 't'), Args::seconds('2w', 't'), Args::seconds('45', 't')]);
        foreach (['5 fortnights', '-1', 'x', ''] as $bad) {
            try {
                Args::seconds($bad, 't');
                self::fail("$bad is a duration");
            } catch (UsageException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('pwsh', Args::shell('powershell'));
        self::assertSame('pwsh', Args::jobShell('powershell'));
        self::assertSame(2048, Args::memMb('2G'));
        self::assertSame(512, Args::memMb('512MB'));
        self::assertSame('c.txt', Args::basename('a\\b/c.txt'));
        $this->usage(static fn () => Args::jobShell('none'));
        $this->usage(static fn () => Args::shell('fish'));
        $this->usage(static fn () => Args::jobName('-x'));
        $this->usage(static fn () => Args::desk(' '));
        $this->usage(static fn () => Args::desk('12 34'));
        $this->usage(static fn () => Args::command([]));
        $this->usage(static fn () => Args::command(['ls', 3]));
        $this->usage(static fn () => Args::cwd("a\0b"));
        $this->usage(static fn () => Args::memMb('2T'));
        try {
            Args::env(['A=B' => 'secret-value']);
            self::fail('a bad name');
        } catch (UsageException $e) {
            self::assertStringNotContainsString('secret-value', $e->getMessage(), 'never the value');
        }
        $this->usage(static fn () => Args::env(['A' => "nul\0"]));
        $this->usage(static fn () => Args::env(['A' => 1]));
        self::assertSame(['STAGE' => 'prod', 'EMPTY' => ''], Args::env(['STAGE' => 'prod', 'EMPTY' => '']));
    }

    public function testErrorClassesAndKinds(): void
    {
        $cases = ['usage' => UsageException::class, 'refused' => RefusedException::class, 'unreachable' => UnreachableException::class, 'connection_lost' => ConnectionLostException::class, 'failed' => OperationFailedException::class, 'protocol' => ProtocolException::class, 'other' => GaiaDeskException::class];
        foreach ($cases as $kind => $class) {
            self::assertSame($class, Errors::forKind($kind, 'm', [])::class);
        }
        self::assertSame('offline', Errors::sdkKind('unreachable', 'offline'));
        self::assertSame('unreachable', Errors::sdkKind('unreachable', 'silent'));
        self::assertNull(Errors::envelope(['error' => null, 'exit' => 0]), "exec's own null error is not an envelope");
        self::assertSame(['kind' => 'refused', 'message' => 'no', 'reason' => 'x', 'desk' => '1'], Errors::envelope(['error' => ['kind' => 'refused', 'message' => 'no', 'reason' => 'x', 'desk' => '1', 'request_id' => 'r']]));
        self::assertSame([254, 1, 130, 255], [Errors::deskOpExit('refused'), Errors::deskOpExit('failed'), Errors::deskOpExit('interrupted'), Errors::deskOpExit('protocol')]);
        $e = new RefusedException('no', ['kind' => 'refused', 'exitCode' => 254, 'status' => 403]);
        self::assertSame(254, $e->getCode());
        $f = $e->with(['json' => ['tokens' => []], 'argv' => ['x']]);
        self::assertSame(RefusedException::class, $f::class);
        self::assertSame([['tokens' => []], ['x'], 403, 'no'], [$f->getJson(), $f->getArgv(), $f->getStatus(), $f->getMessage()]);
        self::assertNull($e->getJson(), 'the original is unchanged');
    }

    public function testWebhookSignatures(): void
    {
        $secret = 'whsec_'.str_repeat('ab', 32);
        $body = '{"id":"evt_1b2c3d4e5f60718293a4b5c6","type":"desk.offline","created":1791300000,"data":{"desk":{"desk_id":"123456789"}}}';
        $sig = Webhooks::sign($secret, $body, 1791300000);
        self::assertMatchesRegularExpression('/^t=1791300000,v1=[0-9a-f]{64}$/', $sig);
        self::assertSame(hash_hmac('sha256', '1791300000.'.$body, $secret), substr($sig, 16));
        self::assertTrue(Webhooks::verify($secret, $sig, $body, 1791300100));
        self::assertFalse(Webhooks::verify($secret, $sig, $body, 1791300301), 'more than five minutes off');
        self::assertFalse(Webhooks::verify($secret, $sig, $body.' ', 1791300000), 'another body');
        self::assertFalse(Webhooks::verify('whsec_other', $sig, $body, 1791300000), 'another secret');
        self::assertFalse(Webhooks::verify($secret, 'garbage', $body, 1791300000));
        self::assertSame('desk.offline', Webhooks::parse($secret, $sig, $body, 1791300000)['type']);
        try {
            Webhooks::parse($secret, $sig, $body, 1);
            self::fail('stale');
        } catch (RefusedException $e) {
            self::assertSame('webhook_signature_invalid', $e->getReason());
        }
    }

    public function testFingerprints(): void
    {
        $hex = str_repeat('AB', 32);
        $want = implode(':', array_fill(0, 32, 'ab'));
        self::assertSame($want, Lan::normalizeFingerprint($hex));
        self::assertSame($want, Lan::normalizeFingerprint('SHA256: '.implode(':', str_split($hex, 2))));
        self::assertSame($want, Lan::normalizeFingerprint(implode(' ', str_split($hex, 2))));
        $this->usage(static fn () => Lan::normalizeFingerprint('ab:cd'));
        $this->usage(static fn () => Lan::normalizeFingerprint(42));
    }

    public function testLocalPlaces(): void
    {
        self::assertSame('/home/ada/.gaiadesk/api.sock', Local::socketPath([], '/home/ada', false));
        self::assertSame('/run/gd/api.sock', Local::socketPath(['GAIADESK_API_DIR' => '/run/gd'], '/home/ada', false));
        self::assertSame('/home/ada/.gaiadesk/api-token', Local::tokenPath(['GAIADESK_API_DIR' => 'relative'], '/home/ada', false), 'a relative dir is ignored');
        self::assertSame('C:\\Users\\Ada\\.gaiadesk\\api-token', Local::tokenPath([], 'C:\\Users\\Ada', true));
        self::assertSame('\\\\.\\pipe\\gaiadesk-api-ada_lovelace', Local::pipeName(['USERNAME' => 'Ada Lovelace']));
        self::assertSame('\\\\.\\pipe\\custom', Local::pipeName(['GAIADESK_API_PIPE' => '\\\\.\\pipe\\custom']));
        self::assertSame('user', Local::pipeUser(''));
    }

    private function usage(callable $f): void
    {
        try {
            $f();
            self::fail('no UsageException');
        } catch (UsageException $e) {
            self::assertSame('usage', $e->getKind());
        }
    }
}
