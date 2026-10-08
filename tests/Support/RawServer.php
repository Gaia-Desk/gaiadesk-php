<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

/**
 * Runs tests/Support/raw-server.php (a TCP "HTTP server" with no framework) in a child
 * process: its mode is switched, and its per-method request counts read, over its
 * control connection.
 */
final class RawServer
{
    /** @var resource */
    private $proc;
    /** @var array<int, resource> */
    private array $pipes;
    /** @var resource */
    private $control;
    public readonly string $url;
    public readonly string $address;

    /**
     * @param int $port    the data port (0: any free one)
     * @param int $delayMs listen on the data port only after this long (until then, connecting is refused)
     */
    public function __construct(string $mode, int $port = 0, int $delayMs = 0)
    {
        $proc = proc_open([\PHP_BINARY, __DIR__.'/raw-server.php', (string) $port, (string) $delayMs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('cannot start the raw server');
        }
        $this->proc = $proc;
        $this->pipes = $pipes;
        $line = fgets($pipes[1]);
        if (false === $line || 1 !== preg_match('/^(\d+) (\d+)$/', trim($line), $m)) {
            throw new \RuntimeException('the raw server did not start: '.stream_get_contents($pipes[2]));
        }
        $this->address = "127.0.0.1:{$m[1]}";
        $this->url = "http://{$this->address}/v1";
        $control = stream_socket_client("tcp://127.0.0.1:{$m[2]}", $errno, $errstr, 5);
        if (false === $control) {
            throw new \RuntimeException("cannot reach the raw server's control port: $errstr");
        }
        stream_set_timeout($control, 10);
        $this->control = $control;
        $this->mode($mode);
    }

    private function command(string $cmd): string
    {
        fwrite($this->control, $cmd."\n");
        $line = fgets($this->control);
        if (false === $line) {
            throw new \RuntimeException("the raw server did not answer `$cmd`");
        }

        return trim($line);
    }

    /** A port nothing listens on (bound, then closed). */
    public static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $s) {
            throw new \RuntimeException('cannot bind a port');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($s, false), ':'), 1);
        fclose($s);

        return $port;
    }

    /** Switch the mode for the next requests (see raw-server.php). */
    public function mode(string $mode): void
    {
        if ('ok' !== $this->command("mode $mode")) {
            throw new \RuntimeException("the raw server refused mode $mode");
        }
    }

    /** Requests received with this method. */
    public function count(string $method): int
    {
        $counts = json_decode($this->command('counts'), true);
        if (!\is_array($counts)) {
            throw new \RuntimeException('the raw server sent no counts');
        }
        $n = $counts[$method] ?? 0;

        return \is_int($n) ? $n : 0;
    }

    /** Stop it: every held socket closes with the process. */
    public function stop(): void
    {
        if (\is_resource($this->proc)) {
            @fclose($this->control);
            proc_terminate($this->proc);
            foreach ($this->pipes as $p) {
                @fclose($p);
            }
            proc_close($this->proc);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
