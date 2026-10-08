<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The base of every error the SDK throws.
 *
 * Every failure of the GaiaDesk API is one error envelope,
 * `{"error": {"kind", "message", "reason"?, "desk"?, "request_id"}}`, the same object
 * `gaiadesk-cli --json` prints. The exception CLASS follows the envelope's `kind`
 * (one of six: usage, refused, unreachable, connection_lost, failed, protocol);
 * {@see getKind()} is the finest kind known: the envelope's `reason` when it is one of
 * the SDK's kinds (so `offline` stays `offline`), else its kind.
 *
 * The exception code ({@see getCode()}) is {@see getExitCode()} (gaiadesk-cli's exit
 * code for the same failure: 254 refused, 1 failed, 130 interrupted, 255 the rest), or 0.
 *
 * @phpstan-type ErrorDetails array{
 *     kind?: string,
 *     reason?: ?string,
 *     desk?: ?string,
 *     exitCode?: ?int,
 *     requestId?: ?string,
 *     status?: ?int,
 *     retryAfter?: ?float,
 *     json?: mixed,
 *     argv?: list<string>,
 *     body?: string,
 * }
 */
class GaiaDeskException extends \RuntimeException
{
    /** The SDK kinds {@see getKind()} may be. */
    public const SDK_KINDS = [
        'usage', 'offline', 'unknown_desk', 'not_online', 'refused', 'network', 'not_signed_in', 'timeout',
        'connection_lost', 'local', 'failed', 'interrupted', 'protocol', 'unreachable',
    ];

    private string $kind;
    private ?string $reason;
    private ?string $desk;
    private ?int $exitCode;
    private ?string $requestId;
    private ?int $status;
    private ?float $retryAfter;
    private mixed $json;
    /** @var list<string> */
    private array $argv;
    private string $body;

    /**
     * @param ErrorDetails $details
     */
    public function __construct(string $message, array $details = [], ?\Throwable $previous = null)
    {
        $this->kind = $details['kind'] ?? 'protocol';
        $this->reason = $details['reason'] ?? null;
        $this->desk = $details['desk'] ?? null;
        $this->exitCode = $details['exitCode'] ?? null;
        $this->requestId = $details['requestId'] ?? null;
        $this->status = $details['status'] ?? null;
        $this->retryAfter = $details['retryAfter'] ?? null;
        $this->json = $details['json'] ?? null;
        $this->argv = $details['argv'] ?? [];
        $this->body = $details['body'] ?? '';
        parent::__construct($message, $this->exitCode ?? 0, $previous);
    }

    /** The finest kind known: `usage`, `refused`, `unreachable`, `offline`, `network`, `timeout`, `failed`, `interrupted`, ... */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** The finer cause (`unknown_desk`, `rate_limited`, `desk_busy`, `e2e_required`, `admin_not_via_api`, ...), or null. */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** The desk the error concerned, when the API said; else null. */
    public function getDesk(): ?string
    {
        return $this->desk;
    }

    /** gaiadesk-cli's exit code for this failure (254 refused, 1 failed, 130 interrupted, 255 the rest), or null. */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /** The request id (`req_…`) of the failed request, to quote to support; else null. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The HTTP status of the failed request (for a held answer: the status it would have had); else null. */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** Seconds to wait before retrying (a 429's `Retry-After`); else null. */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /** The parsed JSON answer (the error envelope), when there was one. */
    public function getJson(): mixed
    {
        return $this->json;
    }

    /**
     * What was called: `["POST /desks/123456789/exec"]`.
     *
     * @return list<string>
     */
    public function getArgv(): array
    {
        return $this->argv;
    }

    /** The start of an answer that was not an error envelope (at most 4 KiB), else ''. */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The details this exception holds.
     *
     * @return array{kind: string, reason: ?string, desk: ?string, exitCode: ?int, requestId: ?string, status: ?int, retryAfter: ?float, json: mixed, argv: list<string>, body: string}
     */
    public function getDetails(): array
    {
        return [
            'kind' => $this->kind,
            'reason' => $this->reason,
            'desk' => $this->desk,
            'exitCode' => $this->exitCode,
            'requestId' => $this->requestId,
            'status' => $this->status,
            'retryAfter' => $this->retryAfter,
            'json' => $this->json,
            'argv' => $this->argv,
            'body' => $this->body,
        ];
    }

    /**
     * A copy of this error (same class, message, code and trace) with some details changed.
     *
     * @param ErrorDetails $changes
     */
    public function with(array $changes): static
    {
        // Exceptions cannot be cloned: copy every property, class by class.
        $copy = (new \ReflectionClass($this))->newInstanceWithoutConstructor();
        for ($c = new \ReflectionClass($this); false !== $c; $c = $c->getParentClass()) {
            foreach ($c->getProperties() as $p) {
                if ($p->isStatic() || $p->getDeclaringClass()->getName() !== $c->getName() || !$p->isInitialized($this)) {
                    continue;
                }
                $p->setValue($copy, $p->getValue($this));
            }
        }
        foreach ($changes as $k => $v) {
            match ($k) {
                'kind' => $copy->kind = $v,
                'reason' => $copy->reason = $v,
                'desk' => $copy->desk = $v,
                'exitCode' => $copy->exitCode = $v,
                'requestId' => $copy->requestId = $v,
                'status' => $copy->status = $v,
                'retryAfter' => $copy->retryAfter = $v,
                'json' => $copy->json = $v,
                'argv' => $copy->argv = $v,
                'body' => $copy->body = $v,
            };
        }

        return $copy;
    }
}
