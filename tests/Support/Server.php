<?php

declare(strict_types=1);

namespace GaiaDesk\Tests\Support;

/** Runs tests/Support/http-server.php in a child process for the length of a test class. */
final class Server
{
    /** @var resource */
    private $proc;
    /** @var array<int, resource> */
    private array $pipes;
    public readonly string $address;

    public function __construct(string $listen, string $mode = 'api', ?string $cert = null)
    {
        $cmd = [\PHP_BINARY, __DIR__.'/http-server.php', $listen, $mode];
        if (null !== $cert) {
            $cmd[] = $cert;
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('cannot start the test server');
        }
        $this->proc = $proc;
        $this->pipes = $pipes;
        $line = fgets($pipes[1]);
        if (false === $line || '' === trim($line)) {
            throw new \RuntimeException('the test server did not start: '.stream_get_contents($pipes[2]));
        }
        $this->address = trim($line);
    }

    public function stop(): void
    {
        if (\is_resource($this->proc)) {
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
