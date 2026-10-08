<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * No answer, or not all of it: the connection could not be made, broke, or a time limit
 * ran out ({@see $timedOut}, which one: {@see $limit}). {@see $answerBegun} tells a
 * request that got no answer at all from an answer that broke off after its status and
 * headers. PSR-18's NetworkExceptionInterface.
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        public readonly bool $timedOut = false,
        public readonly bool $connectFailed = false,
        ?\Throwable $previous = null,
        /** The status and headers had arrived: the answer began, then broke off or stalled. */
        public readonly bool $answerBegun = false,
        /** The limit that ran out: `responseTimeout`, `idleTimeout` or `timeout` (null: none did). */
        public readonly ?string $limit = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
