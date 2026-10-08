<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Calls that must finish within 10 seconds: where pcntl exists, SIGALRM turns a hang
 * into a failed test instead of a stuck run.
 */
trait BoundedCalls
{
    private bool $hung = false;

    /**
     * Run $f, failing the test if it takes longer than 10 seconds (where SIGALRM exists).
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
            throw new AssertionFailedError('no answer within 10 s: the SDK hung');
        }, false);
        pcntl_alarm(10);
        try {
            return $f();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            self::assertFalse($this->hung, 'no answer within 10 s: the SDK hung');
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
}
