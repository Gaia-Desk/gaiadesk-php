<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * No answer: the connection could not be made, broke, or a time limit ran out
 * ({@see $timedOut}). PSR-18's NetworkExceptionInterface.
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        public readonly bool $timedOut = false,
        public readonly bool $connectFailed = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
