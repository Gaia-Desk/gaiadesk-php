<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Api;

use GaiaDesk\E2e\E2eLayer;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Stream\OutputStream;
use GaiaDesk\Stream\StreamExit;
use GaiaDesk\Tests\Support\FakeApi;
use GaiaDesk\Tests\Support\MockClient;
use GaiaDesk\Tests\Support\Recorded;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected const OK = '123456789';

    protected FakeApi $api;
    protected MockClient $http;
    /** @var list<string> */
    protected array $warnings = [];
    /** @var list<float> */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        E2eLayer::resetWarnings();
        $this->api = new FakeApi();
        $this->http = new MockClient($this->api);
    }

    /** @param array<string, mixed> $o */
    protected function gd(array $o = []): GaiaDesk
    {
        return new GaiaDesk(...$o + [
            'apiKey' => 'ak_test',
            'deskToken' => 'gdagt_test',
            'baseUrl' => 'https://api.test/v1',
            'httpClient' => $this->http,
            'onWarning' => function (string $m): void {
                $this->warnings[] = $m;
            },
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    protected function last(): Recorded
    {
        return $this->api->last();
    }

    /** @return array<string, mixed> */
    protected function body(): array
    {
        return $this->last()->json();
    }

    /** @return array{out: string, err: string, exit: StreamExit} */
    protected static function drain(OutputStream $s): array
    {
        $out = '';
        $err = '';
        foreach ($s->text() as $c) {
            if ('stdout' === $c['stream']) {
                $out .= $c['text'];
            } else {
                $err .= $c['text'];
            }
        }

        return ['out' => $out, 'err' => $err, 'exit' => $s->wait()];
    }

    /**
     * @template T of GaiaDeskException
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function throws(string $class, callable $f): GaiaDeskException
    {
        try {
            $f();
        } catch (GaiaDeskException $e) {
            self::assertInstanceOf($class, $e, $e::class.': '.$e->getMessage());

            return $e;
        }
        self::fail("no $class");
    }
}
