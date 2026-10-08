<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

use GaiaDesk\Stream\Utf8Decoder;

/** The plaintext SSE events of a desk's events, as the server maps them (ExecEvents / JobLogEvents). */
final class PlainMap
{
    /** @var array{stdout: Utf8Decoder, stderr: Utf8Decoder} */
    private array $dec;

    public function __construct(private readonly string $kind, private readonly string $desk)
    {
        $this->dec = ['stdout' => new Utf8Decoder(), 'stderr' => new Utf8Decoder()];
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public function map(array $e): array
    {
        if ('stdout' === $e['event'] || 'stderr' === $e['event']) {
            $s = 'logs' === $this->kind ? 'stdout' : $e['event'];
            $t = $this->dec[$s]->decode((string) base64_decode((string) $e['data'], true));
            if ('' === $t) {
                return [];
            }

            return 'logs' === $this->kind ? [['output', ['event' => 'output', 'data' => $t]]] : [[$e['event'], ['event' => $e['event'], 'data' => $t]]];
        }
        if ('exit' === $e['event']) {
            $r = (array) $e['result'];
            if ('logs' === $this->kind) {
                $t = $this->dec['stdout']->decode('', true);
                $out = '' !== $t ? [['output', ['event' => 'output', 'data' => $t]]] : [];
                $out[] = ($r['interrupted'] ?? false) ? ['interrupted', ['event' => 'interrupted']] : ['end', ['event' => 'end', 'job' => $r['job'] ?? null]];

                return $out;
            }
            $v = [];
            foreach (['stdout', 'stderr'] as $s) {
                $t = $this->dec[$s]->decode('', true);
                if ('' !== $t) {
                    $v[] = [$s, ['event' => $s, 'data' => $t]];
                }
            }
            unset($r['stdout'], $r['stderr'], $r['truncated']);
            $v[] = ['exit', $r + ['event' => 'exit']];

            return $v;
        }
        if ('error' !== $e['event']) {
            return [];
        }
        $error = ['kind' => $e['kind'], 'message' => $e['message'], 'desk' => $this->desk];
        if (isset($e['reason'])) {
            $error['reason'] = $e['reason'];
        }

        return [['error', 'exec' === $this->kind ? ['event' => 'error', 'exit' => 'refused' === $e['kind'] ? 254 : 255, 'error' => $error] : ['event' => 'error', 'error' => $error]]];
    }
}
