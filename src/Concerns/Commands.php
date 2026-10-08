<?php

declare(strict_types=1);

namespace GaiaDesk\Concerns;

use GaiaDesk\Exception\CommandException;
use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\Internal\Args;
use GaiaDesk\Internal\Errors;
use GaiaDesk\Internal\Json;
use GaiaDesk\Stream\OutputStream;
use GaiaDesk\Stream\Utf8Decoder;
use GaiaDesk\Transport\Call;
use GaiaDesk\Types;

/**
 * Commands on a desk, and its figures.
 *
 * @internal part of {@see \GaiaDesk\GaiaDesk}
 *
 * @phpstan-import-type ExecResult from Types
 * @phpstan-import-type StatsReport from Types
 */
trait Commands
{
    /**
     * The ExecSpec of a command, checked as gaiadesk-cli checks it.
     *
     * @param string|list<string>        $command
     * @param array<string, string>|null $env
     *
     * @return array<string, mixed>
     */
    private static function execSpec(string|array $command, ?string $shell, int|float|string|null $timeout, ?string $cwd, ?string $stdin, ?array $env): array
    {
        $argv = Args::command($command);
        $spec = \is_string($command) ? ['command' => $command] : ['argv' => $argv];
        if (null !== $shell) {
            $spec['shell'] = Args::shell($shell);
        }
        if (null !== $env) {
            $spec['env'] = Json::object(Args::env($env));
        }
        if (null !== $cwd) {
            $spec['cwd'] = Args::cwd($cwd);
        }
        if (null !== $timeout) {
            $spec['timeout_secs'] = Args::seconds($timeout, 'timeout');
        }
        if (null !== $stdin) {
            $spec['stdin'] = Utf8Decoder::scrub($stdin);
        }

        return $spec;
    }

    /**
     * `POST /desks/{id}/exec`: run one command and wait for it; the same result as
     * `gaiadesk-cli exec --json`.
     *
     * A command that ran answers whatever its exit code (`exit`, `stdout`, `stderr`,
     * `timed_out`, …); one that never ran (refused by the desk, outside a confined token's
     * folder, …) is thrown as its typed exception. With `$check`, a non-zero exit (or a
     * timeout) is a {@see CommandException}. Administrator work (root / SYSTEM) is not
     * available over the API: run it with `gaiadesk-cli exec --admin`.
     *
     * @param string                     $deskId         the desk's nine-digit id
     * @param string|list<string>        $command        a string is ONE command line for the desk's shell, verbatim; a list is
     *                                                   separate arguments, which the desk quotes for its shell (`shell: 'none'`: run directly)
     * @param string|null                $shell          `default`, `none`, `sh`, `bash`, `zsh`, `cmd`, `pwsh` or `powershell` (sent as `pwsh`)
     * @param int|float|string|null      $timeout        seconds, or a duration (`30s`, `10m`); default and at most 15 minutes over the API
     * @param string|null                $cwd            the directory it runs in on the desk (relative: from the desk user's home)
     * @param string|null                $stdin          text written to its stdin, then closed (the API takes text)
     * @param array<string, string>|null $env            environment variables for the command (never logged by the desk)
     * @param bool                       $check          throw a CommandException when it exits non-zero or times out
     * @param string|null                $deskToken      the agent token for this call (instead of the client's)
     * @param int|null                   $wake           if the desk is asleep, ring it and wait up to this many seconds (0-120; the API's default 60)
     * @param string|null                $idempotencyKey a retry with the same key within 24 hours gets the first answer again (the SDK itself never resends it)
     *
     * @return ExecResult
     */
    public function exec(
        string $deskId,
        string|array $command,
        ?string $shell = null,
        int|float|string|null $timeout = null,
        ?string $cwd = null,
        ?string $stdin = null,
        ?array $env = null,
        bool $check = false,
        ?string $deskToken = null,
        ?int $wake = null,
        ?string $idempotencyKey = null,
    ): array {
        $spec = self::execSpec($command, $shell, $timeout, $cwd, $stdin, $env);
        $desk = Args::desk($deskId);
        $path = $this->deskPath($deskId).'/exec';
        $op = "POST $path";
        $limit = (float) ((null !== $timeout ? Args::seconds($timeout, 'timeout') : 900) + ($wake ?? 60) + 60);
        $json = $this->t->json(new Call('POST', $path, json: $spec, deskToken: $deskToken, wake: $wake, idempotencyKey: self::idempotencyKey($idempotencyKey), e2e: ['desk' => $desk, 'op' => 'exec', 'request' => ['op' => 'exec', 'spec' => $spec]], timeout: $limit));
        if (!\is_array($json) || !\is_int($json['exit'] ?? null)) {
            throw new ProtocolException('the GaiaDesk API answered exec without a result', ['kind' => 'protocol', 'argv' => [$op], 'json' => $json]);
        }
        $json['error'] = Errors::execError($json['error'] ?? null);
        /** @var ExecResult $r */
        $r = $json;
        $error = $json['error'];
        $neverRan = null === ($json['remote_code'] ?? null) && true !== ($json['timed_out'] ?? false) && null !== $error;
        if ($neverRan) {
            throw Errors::forKind($error['kind'], '' !== $error['message'] ? $error['message'] : 'the command did not run', [
                'kind' => Errors::sdkKind($error['kind'], $error['reason'] ?? null), 'exitCode' => $r['exit'], 'argv' => [$op], 'json' => $json,
                'reason' => $error['reason'] ?? null, 'desk' => $error['desk'] ?? $r['desk'],
            ]);
        }
        if ($check && 0 !== $r['exit']) {
            $why = $r['timed_out'] ? 'timed out' : "exited {$r['exit']}";
            throw new CommandException("command on desk {$r['desk']} $why", $r, ['exitCode' => $r['exit'], 'argv' => [$op], 'json' => $json, 'desk' => $r['desk'], 'kind' => 'failed']);
        }

        return $r;
    }

    /**
     * `POST /desks/{id}/exec?stream=1`: run one command, its output as it arrives
     * (Server-Sent Events), then how it ended. The same options as {@see exec()}. Iterate
     * the stream for {@see \GaiaDesk\Stream\Chunk}s; `wait()` for the exit; `cancel()` stops
     * the command.
     *
     * @param string|list<string>        $command
     * @param array<string, string>|null $env
     */
    public function execStream(
        string $deskId,
        string|array $command,
        ?string $shell = null,
        int|float|string|null $timeout = null,
        ?string $cwd = null,
        ?string $stdin = null,
        ?array $env = null,
        ?string $deskToken = null,
        ?int $wake = null,
    ): OutputStream {
        $spec = self::execSpec($command, $shell, $timeout, $cwd, $stdin, $env);
        $desk = Args::desk($deskId);
        $path = $this->deskPath($deskId).'/exec';
        $c = new Call('POST', $path, query: ['stream' => 1], json: $spec, accept: 'text/event-stream', deskToken: $deskToken, wake: $wake, e2e: ['desk' => $desk, 'op' => 'exec', 'request' => ['op' => 'exec', 'spec' => $spec, 'stream' => true]], stream: true);

        return new OutputStream("POST $path", 'exec', fn (): array => $this->t->events($c, 'exec'));
    }

    /**
     * `GET /desks/{id}/stats`: CPU, memory, disks and running jobs, as the desk measures them.
     *
     * @return StatsReport
     */
    public function stats(string $deskId, ?string $deskToken = null, ?int $wake = null): array
    {
        $path = $this->deskPath($deskId).'/stats';
        $r = self::object($this->t->json(new Call('GET', $path, deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'stats', 'request' => ['op' => 'stats']], timeout: self::idleFor($wake))), "GET $path", 'its figures');

        /** @var StatsReport $r */
        return $r;
    }
}
