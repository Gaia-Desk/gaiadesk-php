<?php

declare(strict_types=1);

namespace GaiaDesk\Stream;

/**
 * How a stream ended ({@see OutputStream::wait()}).
 *
 * - A command: {@see $exitCode} is its `exit` (the remote code; 124 timed out; 254 the
 *   desk refused; 255 not run or lost), {@see $result} the `exit` event (an ExecResult
 *   without its output), {@see $error} why it did not run or did not finish.
 * - A followed job: 0 when it ended or following stopped (the job goes on), with the
 *   job in {@see $result} (`['job' => …]`); else the error.
 * - Cancelled: 130.
 */
final class StreamExit
{
    /**
     * @param array<string, mixed>|null                                              $result
     * @param array{kind: string, message: string, reason?: string, desk?: string}|null $error
     */
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stderrTail = '',
        public readonly ?array $result = null,
        public readonly ?array $error = null,
    ) {
    }

    /** Did it end well (exit 0, no error)? */
    public function ok(): bool
    {
        return 0 === $this->exitCode && null === $this->error;
    }
}
