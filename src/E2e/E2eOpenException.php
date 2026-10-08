<?php

declare(strict_types=1);

namespace GaiaDesk\E2e;

/**
 * A sealed message did not open. {@see $reason} is the protocol's: `e2e_malformed`,
 * `e2e_decrypt_failed` or `e2e_weak_key`. The SDK turns it into a ProtocolException
 * (an answer) before it reaches a caller.
 */
final class E2eOpenException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
