<?php

declare(strict_types=1);

namespace GaiaDesk\Concerns;

use GaiaDesk\Exception\ProtocolException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Internal\Args;
use GaiaDesk\Internal\Errors;
use GaiaDesk\Internal\Json;
use GaiaDesk\Stream\OutputStream;
use GaiaDesk\Transport\Call;
use GaiaDesk\Types;

/**
 * Background jobs: they run under the desk's GaiaDesk host and outlive the request
 * that started them.
 *
 * @internal part of {@see GaiaDesk}
 *
 * @phpstan-import-type Job from Types
 * @phpstan-import-type JobWaitResult from Types
 */
trait Jobs
{
    /**
     * `POST /desks/{id}/jobs`: start a background job (as `gaiadesk-cli run --detach`).
     *
     * @param string|list<string>        $command   a command line (a string), or arguments joined by the desk
     * @param string|null                $priority  `low`, `normal` or `high`
     * @param int|null                   $cpu       share of the WHOLE machine, 1-100
     * @param int|string|null            $mem       megabytes, or `512M`, `2G`
     * @param bool|null                  $keepAwake keep the desk awake while it runs (null: the desk's default)
     * @param string|null                $shell     `sh`, `bash`, `zsh`, `cmd`, `pwsh` or `powershell` (default: `sh -c` / `cmd /c`)
     * @param array<string, string>|null $env
     *
     * @return Job
     */
    public function runJob(
        string $deskId,
        string $name,
        string|array $command,
        ?string $priority = null,
        ?int $cpu = null,
        int|string|null $mem = null,
        ?bool $keepAwake = null,
        ?string $cwd = null,
        ?string $shell = null,
        ?array $env = null,
        ?string $deskToken = null,
        ?int $wake = null,
        ?string $idempotencyKey = null,
    ): array {
        $argv = Args::command($command, 'run');
        Args::jobName($name);
        $desk = Args::desk($deskId);
        $limits = [];
        if (null !== $priority) {
            if (!\in_array($priority, ['low', 'normal', 'high'], true)) {
                throw Args::usage('priority is low, normal or high');
            }
            $limits['priority'] = $priority;
        }
        if (null !== $cpu) {
            $limits['cpu_percent'] = Args::intIn($cpu, 1, 100, 'cpu (a share of the whole machine)');
        }
        if (null !== $mem) {
            $limits['mem_mb'] = Args::memMb($mem);
        }
        if (null !== $keepAwake) {
            $limits['keep_awake'] = $keepAwake;
        }
        $spec = ['name' => $name, 'command' => $argv, 'limits' => Json::object($limits)];
        if (null !== $cwd) {
            $spec['cwd'] = Args::cwd($cwd);
        }
        if (null !== $shell) {
            $spec['shell'] = Args::jobShell($shell);
        }
        if (null !== $env) {
            $spec['env'] = Json::object(Args::env($env));
        }
        $path = $this->deskPath($deskId).'/jobs';
        $r = self::object($this->t->json(new Call('POST', $path, json: $spec, deskToken: $deskToken, wake: $wake, idempotencyKey: self::idempotencyKey($idempotencyKey), e2e: ['desk' => $desk, 'op' => 'job_start', 'request' => ['op' => 'job_start', 'spec' => $spec]], timeout: self::idleFor($wake))), "POST $path", 'the job');

        /** @var Job $r */
        return $r;
    }

    /**
     * `GET /desks/{id}/jobs/{name}/wait`: `{job, timed_out}` once the job is no longer
     * running (as `gaiadesk-cli wait --json`), or when `$timeout` ran out (`timed_out`
     * true, the job still running; `0` answers at once). The API holds one wait at most
     * 870 seconds, so a longer (or no) timeout waits again until the job ends or the time
     * is up. A held answer that failed after its 200 began is thrown as its typed error.
     *
     * @param int|float|string|null $timeout seconds or a duration (`10m`); null: until the job ends
     *
     * @return JobWaitResult
     */
    public function waitJob(string $deskId, string $name, int|float|string|null $timeout = null, ?string $deskToken = null, ?int $wake = null): array
    {
        Args::jobName($name);
        $desk = Args::desk($deskId);
        $path = $this->deskPath($deskId).'/jobs/'.rawurlencode($name).'/wait';
        $op = "GET $path";
        $total = null === $timeout ? null : Args::seconds($timeout, 'timeout');
        $started = microtime(true);
        while (true) {
            $left = null === $total ? GaiaDesk::API_WAIT_MAX : max(0.0, $total - (microtime(true) - $started));
            $t = (int) min(GaiaDesk::API_WAIT_MAX, ceil($left));
            $json = $this->t->json(new Call('GET', $path, query: ['timeout' => $t], deskToken: $deskToken, wake: $wake, e2e: ['desk' => $desk, 'op' => 'job_wait', 'request' => ['op' => 'job_wait', 'name' => $name, 'timeout_ms' => $t * 1000]], timeout: $t + self::idleFor($wake), idleTimeout: self::idleFor($wake)));
            $env = Errors::envelope($json);
            if (null !== $env) {
                // A held wait that failed after its 200 began: the envelope, in the body.
                $error = \is_array($json) && \is_array($json['error'] ?? null) ? $json['error'] : [];
                throw Errors::forKind($env['kind'], '' !== $env['message'] ? $env['message'] : 'the wait failed', Errors::envelopeDetails($env, [
                    'argv' => [$op], 'json' => $json, 'exitCode' => Errors::deskOpExit($env['kind']),
                    'status' => \is_int($error['status'] ?? null) ? $error['status'] : null,
                    'requestId' => \is_string($error['request_id'] ?? null) ? $error['request_id'] : null,
                ]));
            }
            if (!\is_array($json) || !Json::isObject($json['job'] ?? null) || !\is_bool($json['timed_out'] ?? null)) {
                throw new ProtocolException('the GaiaDesk API answered a wait without a job', ['kind' => 'protocol', 'argv' => [$op], 'json' => $json]);
            }
            /** @var JobWaitResult $json */
            $over = null !== $total && microtime(true) - $started >= $total;
            if (!$json['timed_out'] || $over || 0 === $total) {
                return $json;
            }
        }
    }

    /**
     * `GET /desks/{id}/jobs`: the desk's background jobs.
     *
     * @return list<Job>
     */
    public function jobs(string $deskId, ?string $deskToken = null, ?int $wake = null): array
    {
        $path = $this->deskPath($deskId).'/jobs';

        /** @var list<Job> */
        return self::listOf($this->t->json(new Call('GET', $path, deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'job_list', 'request' => ['op' => 'job_list']], timeout: self::idleFor($wake))), 'jobs', "GET $path");
    }

    /**
     * `DELETE /desks/{id}/jobs/{name}`: stop a job and everything it started; the stopped job.
     *
     * @return Job
     */
    public function killJob(string $deskId, string $name, ?string $deskToken = null, ?int $wake = null): array
    {
        Args::jobName($name);
        $path = $this->deskPath($deskId).'/jobs/'.rawurlencode($name);
        $r = self::object($this->t->json(new Call('DELETE', $path, deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'job_kill', 'request' => ['op' => 'job_kill', 'name' => $name]], timeout: self::idleFor($wake))), "DELETE $path", 'the job');

        /** @var Job $r */
        return $r;
    }

    /**
     * `GET /desks/{id}/jobs/{name}/logs[?tail=]`: the job's output so far (the last `$tail` bytes).
     */
    public function jobLogs(string $deskId, string $name, ?int $tail = null, ?string $deskToken = null, ?int $wake = null): string
    {
        Args::jobName($name);
        if (null !== $tail && $tail < 0) {
            throw Args::usage('tail is a number of bytes');
        }
        $request = ['op' => 'job_logs', 'name' => $name];
        if (null !== $tail) {
            $request['tail'] = $tail;
        }
        $path = $this->deskPath($deskId).'/jobs/'.rawurlencode($name).'/logs';
        $r = $this->t->json(new Call('GET', $path, query: ['tail' => $tail], deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'job_logs', 'request' => $request], timeout: self::idleFor($wake)));

        return \is_array($r) && \is_string($r['output'] ?? null) ? $r['output'] : '';
    }

    /**
     * `GET /desks/{id}/jobs/{name}/logs?follow=1`: the job's output as it is written
     * (Server-Sent Events), until it ends. Cancelling stops following; the job goes on.
     */
    public function followJobLogs(string $deskId, string $name, ?int $tail = null, ?string $deskToken = null, ?int $wake = null): OutputStream
    {
        Args::jobName($name);
        if (null !== $tail && $tail < 0) {
            throw Args::usage('tail is a number of bytes');
        }
        $request = ['op' => 'job_logs', 'name' => $name];
        if (null !== $tail) {
            $request['tail'] = $tail;
        }
        $request['follow'] = true;
        $path = $this->deskPath($deskId).'/jobs/'.rawurlencode($name).'/logs';
        $c = new Call('GET', $path, query: ['follow' => 1, 'tail' => $tail], accept: 'text/event-stream', deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'job_logs', 'request' => $request], idleTimeout: self::idleFor($wake), stream: true);

        return new OutputStream("GET $path", 'logs', fn (): array => $this->t->events($c, 'logs'), $name);
    }
}
