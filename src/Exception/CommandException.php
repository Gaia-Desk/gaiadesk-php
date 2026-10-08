<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * `exec(..., check: true)`: the remote command exited non-zero (or timed out).
 * {@see getResult()} is its whole ExecResult.
 */
class CommandException extends GaiaDeskException
{
    /** @var array<string, mixed> */
    private array $result;

    /**
     * @param array<string, mixed> $result
     * @param array{kind?: string, reason?: ?string, desk?: ?string, exitCode?: ?int, requestId?: ?string, status?: ?int, retryAfter?: ?float, json?: mixed, argv?: list<string>, body?: string} $details
     */
    public function __construct(string $message, array $result, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
        $this->result = $result;
    }

    /**
     * The command's ExecResult (`exit`, `stdout`, `stderr`, `timed_out`, ...).
     *
     * @return array<string, mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
