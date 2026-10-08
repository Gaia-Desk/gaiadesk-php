<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The LAN gateway's certificate did not match the pinned fingerprint: it is not the desk
 * you pinned. Do not proceed. Nothing of the request was sent.
 */
class FingerprintMismatchException extends UnreachableException
{
    private string $expected;
    private string $actual;

    /**
     * @param array{kind?: string, reason?: ?string, desk?: ?string, exitCode?: ?int, requestId?: ?string, status?: ?int, retryAfter?: ?float, json?: mixed, argv?: list<string>, body?: string} $details
     */
    public function __construct(string $message, string $expected, string $actual, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
        $this->expected = $expected;
        $this->actual = $actual;
    }

    /** The pinned fingerprint (`ab:cd:…`). */
    public function getExpected(): string
    {
        return $this->expected;
    }

    /** The fingerprint the server presented (`ab:cd:…`), or '' when it presented none. */
    public function getActual(): string
    {
        return $this->actual;
    }
}
