<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Http\NetworkException;

/**
 * When a failed request is sent again, and after how long: the one retry policy of every
 * GaiaDesk SDK. A request is sent again only when that cannot run anything twice:
 *
 * - the connection was never made (nothing was sent): any method;
 * - the connection was lost after sending (closed or reset before any answer), or the
 *   answer was 502, 503 or 504: GETs only (a 503 saying the API, desk operations or the
 *   local API are switched off is not retried);
 * - 429 (`rate_limited`, `desk_busy`) and 409 `idempotency_key_in_flight`: any method.
 *
 * Never: a timeout, an answer that had begun, anything else. 429 and 503 wait for
 * `Retry-After` (one longer than {@see $maxRetryWait} is not waited for); otherwise
 * exponential backoff: `min(maxDelay, baseDelay * 2^n)` times a random 0.5 to 1.0.
 *
 * @internal
 */
final class Retry
{
    public const MAX_RETRIES = 2;
    public const BASE_DELAY = 0.25;
    public const MAX_DELAY = 8.0;
    public const MAX_RETRY_WAIT = 60.0;
    /** The reasons of a 503 that will not change by asking again. */
    public const PERMANENT_UNAVAILABLE = ['api_disabled', 'desk_ops_disabled', 'local_api_off'];

    /** @var \Closure(): float a random number in [0, 1] */
    private readonly \Closure $random;

    /**
     * @param (callable(): float)|null $random a random number in [0, 1] (for tests)
     */
    public function __construct(
        public readonly int $maxRetries = self::MAX_RETRIES,
        public readonly float $baseDelay = self::BASE_DELAY,
        public readonly float $maxDelay = self::MAX_DELAY,
        public readonly float $maxRetryWait = self::MAX_RETRY_WAIT,
        ?callable $random = null,
    ) {
        $this->random = null !== $random ? $random(...) : static fn (): float => mt_rand() / mt_getrandmax();
    }

    /** The wait before retry number $n + 1 (n = 0 for the first): `min(maxDelay, baseDelay * 2^n)` times 0.5 to 1.0. */
    public function backoff(int $n): float
    {
        return min($this->maxDelay, $this->baseDelay * (2 ** min($n, 62))) * (0.5 + 0.5 * ($this->random)());
    }

    /**
     * How long to wait before sending the request again after attempt $attempt (0: the first)
     * failed with $e, or null not to send it again.
     */
    public function delay(GaiaDeskException $e, string $method, int $attempt): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        $status = $e->getStatus();
        $reason = $e->getReason();
        $get = 'GET' === strtoupper($method);
        if (429 === $status || (409 === $status && 'idempotency_key_in_flight' === $reason)) {
            return $this->waitFor($e, $attempt); // refused before acting: any method
        }
        if (null === $status) {
            if (self::neverSent($e)) {
                return $this->backoff($attempt); // nothing was sent: any method
            }

            return $get && 'network' === $e->getKind() ? $this->backoff($attempt) : null;
        }
        if (!$get) {
            return null;
        }
        if (503 === $status) {
            return \in_array($reason, self::PERMANENT_UNAVAILABLE, true) ? null : $this->waitFor($e, $attempt);
        }

        return 502 === $status || 504 === $status ? $this->backoff($attempt) : null;
    }

    /** Retry-After when the answer gave one (null: longer than maxRetryWait, so not waited for), else backoff. */
    private function waitFor(GaiaDeskException $e, int $attempt): ?float
    {
        $after = $e->getRetryAfter();
        if (null === $after) {
            return $this->backoff($attempt);
        }

        return $after <= $this->maxRetryWait ? max(0.0, $after) : null;
    }

    /** Did the request fail before any byte of it was written (a connection that was never made)? */
    public static function neverSent(\Throwable $e): bool
    {
        for ($p = $e; null !== $p; $p = $p->getPrevious()) {
            if ($p instanceof NetworkException) {
                return $p->connectFailed && !$p->timedOut;
            }
        }

        return false;
    }
}
