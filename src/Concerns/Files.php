<?php

declare(strict_types=1);

namespace GaiaDesk\Concerns;

use GaiaDesk\E2e\Answers;
use GaiaDesk\Exception\ConnectionLostException;
use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\OperationFailedException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Internal\Args;
use GaiaDesk\Transport\Call;
use GaiaDesk\Types;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * Files to and from a desk: at most 256 MB each through the API, streamed both ways
 * (never held in memory whole), sealed end to end when the desk can open it.
 *
 * @internal part of {@see GaiaDesk}
 *
 * @phpstan-import-type CopyResult from Types
 */
trait Files
{
    /**
     * `PUT /desks/{id}/files?path=`: upload one local file. A `$remote` ending in `/` (or
     * empty) is a folder: the file keeps its name there. A `$remote` naming an existing
     * folder on the desk receives the file under it.
     *
     * @return CopyResult
     */
    public function upload(string $local, string $deskId, string $remote, ?string $deskToken = null, ?int $wake = null): array
    {
        Args::desk($deskId);
        if (is_dir($local)) {
            throw $this->t->notServed("uploading the folder $local", 'the API copies single files; upload each file, or copy folders with gaiadesk-cli cp --recursive');
        }
        $size = @filesize($local);
        $h = false === $size ? false : @fopen($local, 'rb');
        if (false === $size || false === $h) {
            throw new GaiaDeskException("cannot read $local: ".(error_get_last()['message'] ?? 'no such file'), ['kind' => 'local', 'reason' => 'local', 'argv' => ['upload']]);
        }
        $target = '' === $remote || 1 === preg_match('#[\\\\/]$#', $remote) ? $remote.Args::basename($local) : $remote;
        try {
            return $this->uploadFrom($h, $deskId, $target, $deskToken, $wake);
        } finally {
            if (\is_resource($h)) {
                fclose($h);
            }
        }
    }

    /**
     * `PUT /desks/{id}/files?path=`: upload bytes (a string), a readable stream resource or
     * a PSR-7 stream, as the file `$remote` on the desk.
     *
     * @param string|resource|StreamInterface $data
     *
     * @return CopyResult
     */
    public function uploadFrom(mixed $data, string $deskId, string $remote, ?string $deskToken = null, ?int $wake = null): array
    {
        $remote = Args::remotePath($remote);
        $desk = Args::desk($deskId);
        if (\is_string($data)) {
            $body = Stream::create($data);
        } elseif ($data instanceof StreamInterface) {
            $body = $data;
        } elseif (\is_resource($data)) {
            $body = Stream::create($data);
        } else {
            throw Args::usage('upload data is a string, a stream resource or a PSR-7 stream');
        }
        $size = $body->getSize();
        if (null === $size) {
            // Its size goes in the (sealed) request: spool a stream of unknown length first.
            $tmp = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (false === $tmp) {
                throw new GaiaDeskException('cannot open a temporary buffer for the upload', ['kind' => 'local', 'reason' => 'local']);
            }
            while (!$body->eof()) {
                fwrite($tmp, $body->read(65536));
                if (ftell($tmp) > GaiaDesk::API_FILE_LIMIT) {
                    break;
                }
            }
            rewind($tmp);
            $body = Stream::create($tmp);
            $size = (int) $body->getSize();
        }
        if ($size > GaiaDesk::API_FILE_LIMIT) {
            throw new UsageException("the file is $size bytes; the API takes files up to 256 MB (copy larger ones with gaiadesk-cli cp)", ['kind' => 'usage', 'argv' => ['upload']]);
        }
        $path = $this->deskPath($deskId).'/files';
        $r = $this->t->json(new Call('PUT', $path, query: ['path' => $remote], bytes: $body, size: $size, deskToken: $deskToken, wake: $wake, e2e: ['desk' => $desk, 'op' => 'file_put', 'request' => ['op' => 'file_put', 'path' => $remote, 'size' => $size]], idleTimeout: self::idleFor($wake)));
        $r = self::object($r, "PUT $path", 'a copy result');
        if (\is_array($r['failed'] ?? null) && [] !== $r['failed']) {
            throw new OperationFailedException(\count($r['failed']).' file(s) failed to copy', ['exitCode' => 1, 'argv' => ["PUT $path"], 'json' => $r, 'kind' => 'failed', 'desk' => $desk]);
        }

        /** @var CopyResult $r */
        return $r;
    }

    /**
     * `GET /desks/{id}/files?path=`: the file's bytes, written to `$sink` (a writable
     * stream resource or PSR-7 stream) as they arrive. A transfer that breaks off is a
     * ConnectionLostException (`incomplete`), never a clean short file.
     *
     * @param resource|StreamInterface $sink
     *
     * @return int the number of bytes written
     */
    public function downloadTo(string $deskId, string $remote, mixed $sink, ?string $deskToken = null, ?int $wake = null): int
    {
        $remote = Args::remotePath($remote);
        $desk = Args::desk($deskId);
        if (!$sink instanceof StreamInterface && !\is_resource($sink)) {
            throw Args::usage('the download sink is a writable stream resource or a PSR-7 stream');
        }
        $path = $this->deskPath($deskId).'/files';
        $op = "GET $path";
        $written = 0;
        $write = static function (string $b) use ($sink, &$written): void {
            if ('' === $b) {
                return;
            }
            $ok = $sink instanceof StreamInterface ? $sink->write($b) : fwrite($sink, $b);
            if (false === $ok) {
                throw new GaiaDeskException('cannot write the download', ['kind' => 'local', 'reason' => 'local', 'argv' => ['download']]);
            }
            $written += \strlen($b);
        };
        [$res, $seal] = $this->t->request(new Call('GET', $path, query: ['path' => $remote], accept: 'application/octet-stream', deskToken: $deskToken, wake: $wake, e2e: ['desk' => $desk, 'op' => 'file_get', 'request' => ['op' => 'file_get', 'path' => $remote]], idleTimeout: self::idleFor($wake), stream: true));
        $body = $res->getBody();
        try {
            if (null !== $seal) {
                Answers::openDownload($body, $seal, $write, [$op]);
            } else {
                while (!$body->eof()) {
                    $write($body->read(65536));
                }
            }
        } catch (\RuntimeException $e) {
            if ($e instanceof GaiaDeskException) {
                throw $e;
            }
            throw new ConnectionLostException('the download broke off: '.$e->getMessage(), ['kind' => 'connection_lost', 'reason' => 'incomplete', 'argv' => [$op], 'desk' => $desk, 'exitCode' => 255], $e);
        } finally {
            $body->close();
        }

        return $written;
    }

    /**
     * `GET /desks/{id}/files?path=`: the file's bytes, in memory.
     */
    public function downloadBytes(string $deskId, string $remote, ?string $deskToken = null, ?int $wake = null): string
    {
        $buf = fopen('php://memory', 'w+b');
        if (false === $buf) {
            throw new GaiaDeskException('cannot open a memory buffer', ['kind' => 'local', 'reason' => 'local']);
        }
        try {
            $this->downloadTo($deskId, $remote, $buf, $deskToken, $wake);
            rewind($buf);

            return (string) stream_get_contents($buf);
        } finally {
            fclose($buf);
        }
    }

    /**
     * `GET /desks/{id}/files?path=` into a local file. A `$local` folder (or one ending in
     * `/`) keeps the remote name. The file appears only once it is whole (written beside it,
     * then renamed).
     *
     * @return CopyResult
     */
    public function download(string $deskId, string $remote, string $local, ?string $deskToken = null, ?int $wake = null): array
    {
        $desk = Args::desk($deskId);
        $remote = Args::remotePath($remote);
        if ('' === $local) {
            throw Args::usage('a local path is required');
        }
        $started = microtime(true);
        $isDir = 1 === preg_match('#[\\\\/]$#', $local) || is_dir($local);
        $dest = $isDir ? rtrim($local, '\\/').\DIRECTORY_SEPARATOR.Args::basename($remote) : $local;
        $tmp = $dest.'.gaiadesk-part-'.bin2hex(random_bytes(4));
        $h = @fopen($tmp, 'xb');
        if (false === $h) {
            throw new GaiaDeskException("cannot write $dest: ".(error_get_last()['message'] ?? 'cannot create it'), ['kind' => 'local', 'reason' => 'local', 'argv' => ['download']]);
        }
        try {
            $bytes = $this->downloadTo($deskId, $remote, $h, $deskToken, $wake);
            fclose($h);
            $h = null;
            if (!@rename($tmp, $dest)) {
                throw new GaiaDeskException("cannot write $dest: ".(error_get_last()['message'] ?? 'rename failed'), ['kind' => 'local', 'reason' => 'local', 'argv' => ['download']]);
            }
        } finally {
            if (\is_resource($h)) {
                fclose($h);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        return ['direction' => 'download', 'desk' => $desk, 'destination' => $dest, 'files' => 1, 'dirs' => 0, 'bytes' => $bytes, 'resumed_bytes' => 0, 'failed' => [], 'seconds' => microtime(true) - $started];
    }
}
