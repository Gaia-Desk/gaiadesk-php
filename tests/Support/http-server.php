<?php

declare(strict_types=1);

/*
 * A real HTTP/1.1 server around FakeApi, for the tests of the SDK's own clients:
 *
 *   php tests/Support/http-server.php <listen> <mode> [<cert.pem>]
 *
 * <listen>: tcp://127.0.0.1:0, unix:///path/api.sock, or tls://127.0.0.1:0 (with the
 * certificate+key PEM). Prints the address it listens on, then serves one connection at
 * a time. Answers are chunked and written piece by piece with a short pause between, so
 * a client that reads as it arrives can be told from one that waits for the end.
 *
 * Extra routes: GET /__requests (what was received, as JSON), GET /__sleep?s=N (answers
 * after N seconds), GET /__short (a Content-Length the body never reaches).
 */

require __DIR__.'/../../vendor/autoload.php';

use GaiaDesk\Tests\Support\FakeApi;

$listen = (string) ($argv[1] ?? 'tcp://127.0.0.1:0');
$mode = (string) ($argv[2] ?? 'api');
$cert = $argv[3] ?? null;
$ctx = stream_context_create(null !== $cert ? ['ssl' => ['local_cert' => $cert, 'verify_peer' => false]] : []);
$server = @stream_socket_server($listen, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $ctx);
if (false === $server) {
    fwrite(\STDERR, "cannot listen on $listen: $errstr\n");
    exit(1);
}
echo str_starts_with($listen, 'unix://') ? $listen : stream_socket_get_name($server, false), "\n";
flush();

$api = new FakeApi([
    '111111111' => ['secret' => (string) hex2bin('0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20')],
], $mode);

/** @param resource $c */
function srvLine($c, string &$buf): ?string
{
    while (false === ($i = strpos($buf, "\r\n"))) {
        $d = fread($c, 8192);
        if (false === $d || ('' === $d && feof($c))) {
            return null;
        }
        $buf .= $d;
    }
    $line = substr($buf, 0, $i);
    $buf = (string) substr($buf, $i + 2);

    return $line;
}

/** @param resource $c */
function srvRead($c, string &$buf, int $n): ?string
{
    while (strlen($buf) < $n) {
        $d = fread($c, 65536);
        if (false === $d || ('' === $d && feof($c))) {
            return null;
        }
        $buf .= $d;
    }
    $out = substr($buf, 0, $n);
    $buf = (string) substr($buf, $n);

    return $out;
}

/** @param resource $c */
function srvPlain($c, string $status, string $type, string $body, ?int $length = null): void
{
    @fwrite($c, "HTTP/1.1 $status\r\nContent-Type: $type\r\nContent-Length: ".($length ?? strlen($body))."\r\nConnection: close\r\n\r\n".$body);
    @fclose($c);
}

while (is_resource($server)) {
    $c = @stream_socket_accept($server, -1);
    if (false === $c) {
        continue;
    }
    $buf = '';
    $start = srvLine($c, $buf);
    if (null === $start || '' === $start) {
        @fclose($c);
        continue;
    }
    [$method, $target] = explode(' ', $start) + ['', '/'];
    $headers = [];
    while (null !== ($l = srvLine($c, $buf)) && '' !== $l) {
        [$k, $v] = explode(':', $l, 2) + ['', ''];
        $headers[strtolower(trim($k))] = trim($v);
    }
    $body = '';
    if (isset($headers['content-length'])) {
        $body = (string) srvRead($c, $buf, (int) $headers['content-length']);
    } elseif (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
        while (null !== ($l = srvLine($c, $buf))) {
            $n = (int) hexdec($l);
            if (0 === $n) {
                srvLine($c, $buf);
                break;
            }
            $body .= (string) srvRead($c, $buf, $n);
            srvLine($c, $buf);
        }
    }
    $path = (string) parse_url($target, \PHP_URL_PATH);
    if ('/__requests' === $path) {
        srvPlain($c, '200 OK', 'application/json', (string) json_encode(array_map(static fn ($r) => ['method' => $r->method, 'path' => $r->path, 'query' => $r->query, 'headers' => $r->headers, 'body' => base64_encode($r->body)], $api->requests)));
        continue;
    }
    if ('/__sleep' === $path) {
        parse_str((string) parse_url($target, \PHP_URL_QUERY), $q);
        usleep((int) ((float) ($q['s'] ?? 1) * 1e6));
        srvPlain($c, '200 OK', 'application/json', '{}');
        continue;
    }
    if ('/__short' === $path) {
        srvPlain($c, '200 OK', 'application/octet-stream', 'only this', 1000);
        continue;
    }
    $r = $api->handle($method, $target, $headers, $body);
    $head = "HTTP/1.1 {$r->status} X\r\n";
    foreach ($r->headers as $k => $v) {
        $head .= "$k: $v\r\n";
    }
    $head .= "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
    @fwrite($c, $head);
    foreach ($r->chunks as $chunk) {
        if ('' === $chunk) {
            continue;
        }
        if (false === @fwrite($c, dechex(strlen($chunk))."\r\n".$chunk."\r\n")) {
            break;
        }
        @fflush($c);
        usleep(3000);
    }
    @fwrite($c, "0\r\n\r\n");
    @fclose($c);
}
