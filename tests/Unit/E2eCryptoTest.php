<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Unit;

use GaiaDesk\E2e\CallerSeal;
use GaiaDesk\E2e\Crypto;
use GaiaDesk\E2e\E2eOpenException;
use GaiaDesk\Tests\Support\DeskSeal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end encryption's crypto: the protocol's fixed test vectors byte for byte
 * (protocol/src/e2e/vectors.json, copied to tests/fixtures/e2e-vectors.json), round trips,
 * and every way a message must fail to open.
 */
final class E2eCryptoTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $v;

    public static function setUpBeforeClass(): void
    {
        $v = json_decode((string) file_get_contents(__DIR__.'/../fixtures/e2e-vectors.json'), true);
        self::assertIsArray($v);
        self::$v = $v;
    }

    private static function s(string $k): string
    {
        $x = self::$v[$k];
        self::assertIsString($x);

        return $x;
    }

    /** @return array<array-key, mixed> */
    private static function a(string $k): array
    {
        $x = self::$v[$k];
        self::assertIsArray($x);

        return $x;
    }

    /** @return array{request: array{v: int, pub: string, nonce: string, ciphertext: string}, seal: CallerSeal} */
    private static function vectorSeal(?string $desk = null, ?string $op = null): array
    {
        $deskPub = Crypto::x25519Public((string) hex2bin(self::s('desk_secret_hex')));
        $req = self::a('request');

        return Crypto::sealRequestWith((string) hex2bin(self::s('eph_secret_hex')), (string) Crypto::b64decode((string) $req['nonce']), $deskPub, $desk ?? self::s('desk_id'), $op ?? self::s('op'), self::s('request_plaintext'));
    }

    public function testDeskKeySealedRequestAndHeaderByteForByte(): void
    {
        self::assertSame(self::s('desk_pub'), Crypto::b64url(Crypto::x25519Public((string) hex2bin(self::s('desk_secret_hex')))));
        $r = self::vectorSeal();
        self::assertEquals(self::a('request'), $r['request']);
        self::assertSame(self::s('request_header'), Crypto::requestHeader($r['request']));
    }

    public function testAssociatedData(): void
    {
        self::assertSame(self::s('aad_request_hex'), bin2hex(Crypto::associatedData('request', self::s('desk_id'), self::s('op'))));
        self::assertSame(self::s('aad_event_1_hex'), bin2hex(Crypto::associatedData('event', self::s('desk_id'), self::s('op'), 1)));
    }

    public function testEventsOpenAndInputsSealToTheSameCiphertext(): void
    {
        $seal = self::vectorSeal()['seal'];
        foreach (self::a('events') as $e) {
            self::assertIsArray($e);
            self::assertSame($e['plaintext'], $seal->openEvent($e));
        }
        foreach (self::a('inputs') as $i) {
            self::assertIsArray($i);
            self::assertSame(['seq' => $i['seq'], 'nonce' => $i['nonce'], 'ciphertext' => $i['ciphertext']], $seal->sealInputWith((string) Crypto::b64decode((string) $i['nonce']), (bool) $i['last'], (string) $i['data']));
        }
        $again = self::vectorSeal()['seal'];
        $events = self::a('events');
        self::assertSame(['event' => 'stdout', 'data' => 'dmVjdG9yCg=='], $again->openDeskEvent($events[0]));
        self::assertSame(['event' => 'exit', 'result' => ['exit' => 0]], $again->openDeskEvent($events[1]));
    }

    public function testTheDeskSideOpensTheRequestAndTheInputFrames(): void
    {
        $desk = DeskSeal::openRequest((string) hex2bin(self::s('desk_secret_hex')), self::s('desk_id'), self::s('op'), self::a('request'));
        self::assertSame(self::s('request_plaintext'), $desk['plain']);
        $inputs = self::a('inputs');
        self::assertIsArray($inputs[0]);
        self::assertIsArray($inputs[1]);
        self::assertSame(['last' => false, 'data' => 'hello '], $desk['seal']->openInput($inputs[0]));
        self::assertSame(['last' => true, 'data' => 'world'], $desk['seal']->openInput($inputs[1]));
        $events = self::a('events');
        self::assertIsArray($events[0]);
        $ev = $desk['seal']->sealEventWith((string) Crypto::b64decode((string) $events[0]['nonce']), (string) $events[0]['plaintext']);
        self::assertSame(['seq' => 0, 'nonce' => $events[0]['nonce'], 'ciphertext' => $events[0]['ciphertext']], $ev);
    }

    public function testRoundTripWithFreshKeys(): void
    {
        $secret = (string) hex2bin(self::s('desk_secret_hex'));
        $deskPub = Crypto::x25519Public($secret);
        $r = Crypto::sealRequest($deskPub, '123456789', 'file_put', ['op' => 'file_put', 'path' => '/tmp/x', 'size' => 3]);
        $desk = DeskSeal::openRequest($secret, '123456789', 'file_put', $r['request']);
        $inner = json_decode($desk['plain'], true);
        self::assertIsArray($inner);
        self::assertSame(1, $inner['v']);
        self::assertLessThan(5, abs($inner['ts'] - time()));
        self::assertSame(['op' => 'file_put', 'path' => '/tmp/x', 'size' => 3], $inner['request']);
        self::assertSame(['last' => true, 'data' => 'abc'], $desk['seal']->openInput($r['seal']->sealInput(true, 'abc')));
        foreach ([['event' => 'stdout', 'data' => base64_encode('é')], ['event' => 'exit', 'result' => ['ok' => 1]]] as $e) {
            self::assertSame($e, $r['seal']->openDeskEvent($desk['seal']->sealEvent($e)));
        }
        $other = Crypto::sealRequest($deskPub, '123456789', 'exec', ['op' => 'exec', 'spec' => []]);
        self::assertNotSame($other['request']['pub'], $r['request']['pub'], 'a fresh ephemeral key per operation');
        self::assertNotSame($other['request']['nonce'], $r['request']['nonce']);
    }

    private static function flip(string $b64, int $at = 0): string
    {
        $u = (string) Crypto::b64decode($b64);
        $u[$at] = \chr(\ord($u[$at]) ^ 1);

        return Crypto::b64url($u);
    }

    public function testTamperedRequestsDoNotOpen(): void
    {
        $req = self::vectorSeal()['request'];
        foreach ([['ciphertext', 5], ['nonce', 0], ['pub', 3]] as [$field, $at]) {
            $bad = $req;
            $bad[$field] = self::flip($bad[$field], $at);
            try {
                DeskSeal::openRequest((string) hex2bin(self::s('desk_secret_hex')), self::s('desk_id'), self::s('op'), $bad);
                self::fail("a flipped $field opened");
            } catch (E2eOpenException $e) {
                self::assertContains($e->reason, ['e2e_decrypt_failed', 'e2e_weak_key']);
            }
        }
    }

    public function testTamperedEventsDoNotOpen(): void
    {
        $e0 = self::a('events')[0];
        self::assertIsArray($e0);
        foreach ([['ciphertext', 2], ['nonce', 23]] as [$field, $at]) {
            $bad = $e0;
            $bad[$field] = self::flip((string) $bad[$field], $at);
            $this->assertOpenFails('e2e_decrypt_failed', static fn () => self::vectorSeal()['seal']->openEvent($bad));
        }
        $this->assertOpenFails('e2e_malformed', static fn () => self::vectorSeal()['seal']->openEvent(['ciphertext' => 'AAAA'] + $e0));
        $this->assertOpenFails('e2e_malformed', static fn () => self::vectorSeal()['seal']->openEvent(['seq' => 0]));
    }

    public function testAnotherDeskOrOperationDoesNotOpen(): void
    {
        $secret = (string) hex2bin(self::s('desk_secret_hex'));
        $req = self::vectorSeal()['request'];
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => DeskSeal::openRequest($secret, '481902775', self::s('op'), $req));
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => DeskSeal::openRequest($secret, self::s('desk_id'), 'job_start', $req));
        $e0 = self::a('events')[0];
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => self::vectorSeal('481902775')['seal']->openEvent($e0));
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => self::vectorSeal(null, 'stats')['seal']->openEvent($e0));
    }

    public function testEventsReorderedReplayedRenumberedOrSkippedDoNotOpen(): void
    {
        [$e0, $e1] = self::a('events');
        self::assertIsArray($e0);
        self::assertIsArray($e1);
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => self::vectorSeal()['seal']->openEvent($e1));
        $s = self::vectorSeal()['seal'];
        $s->openEvent($e0);
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => $s->openEvent($e0));
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => self::vectorSeal()['seal']->openEvent(['seq' => 0] + $e1));
        $s = self::vectorSeal()['seal'];
        $s->openEvent($e0);
        $this->assertOpenFails('e2e_decrypt_failed', static fn () => $s->openEvent(['seq' => 1] + $e0));
        $s = self::vectorSeal()['seal'];
        $s->openEvent($e0);
        self::assertSame($e1['plaintext'], $s->openEvent($e1), 'in order it opens');
    }

    public function testLowOrderKeysAndDeskKeys(): void
    {
        $this->assertOpenFails('e2e_weak_key', static fn () => Crypto::x25519((string) hex2bin(self::s('eph_secret_hex')), str_repeat("\0", 32)));
        self::assertSame(32, \strlen((string) Crypto::deskKey(self::s('desk_pub'))));
        self::assertSame(32, \strlen((string) Crypto::deskKey(self::s('desk_pub').'=')), 'padding tolerated');
        self::assertNull(Crypto::deskKey('AAAA'));
        self::assertNull(Crypto::deskKey('not base64!'));
        self::assertNull(Crypto::deskKey(42));
    }

    /** @return iterable<array{string}> */
    public static function samples(): iterable
    {
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', "\xff\xfe\x00"] as $s) {
            yield [$s];
        }
    }

    #[DataProvider('samples')]
    public function testBase64(string $s): void
    {
        self::assertSame(base64_encode($s), Crypto::b64encode($s));
        self::assertSame($s, Crypto::b64decode(Crypto::b64url($s)));
        self::assertSame($s, Crypto::b64decode(base64_encode($s)));
        self::assertStringNotContainsString('=', Crypto::b64url($s));
    }

    public function testBase64RefusesWhatIsNot(): void
    {
        self::assertNull(Crypto::b64decode('a'));
        self::assertNull(Crypto::b64decode('ab$c'));
    }

    private function assertOpenFails(string $reason, callable $f): void
    {
        try {
            $f();
            self::fail('it opened');
        } catch (E2eOpenException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
