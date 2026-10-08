<?php

declare(strict_types=1);

/*
 * A raw TCP "HTTP server" with no framework in between, for the ways a real server or
 * proxy fails (run by {@see GaiaDesk\Tests\Support\RawServer} as a child process):
 *
 *   php tests/Support/raw-server.php [<data port> [<delay ms>]]
 *
 * Prints `<data port> <control port>` and serves both on 127.0.0.1 from one select loop.
 * With a delay, the data port is only listened on after that many milliseconds (until
 * then a connection to it is refused); the control port is up at once.
 * The control port takes one line per command and answers one line:
 *
 *   mode <name>   switch the mode for the next requests        -> ok
 *   counts        the requests received, per method, as JSON   -> {"GET":3,"PUT":1}
 *
 * Modes (what happens once a request's headers are in; each request is counted first):
 *
 *   close        close (FIN) before any response byte, the body unread
 *   reset        reset (RST: SO_LINGER 0, needs ext-sockets; else a plain close) before any response byte
 *   close-body   read the whole body (Content-Length or chunked), then close before any response byte
 *   stall-body   200 octet-stream, chunked, one chunk "hello", then nothing, the socket held open
 *   stall-json   200 JSON with Content-Length 100 and the body {"desk": then nothing, held open
 *   stall-events 200 text/event-stream, chunked, one stdout event, then nothing, held open
 *   silent       never answer, held open (and never read again: a large body fills the buffers)
 *   keepalive    the first request on a connection gets 200 {} and the connection is kept;
 *                any later one on it is closed before a response byte (libcurl's re-send trap)
 *   status <code> [<retry-after>|-] [<reason>|-]
 *                the body read, then that status with an error envelope (and Retry-After), closed
 *   json <body>  the body read, then 200 with that JSON, closed
 *
 * Held sockets are not read again, and close when the process ends (the test stops it).
 */

$dataPort = (int) ($argv[1] ?? 0);
$listenAt = microtime(true) + (int) ($argv[2] ?? 0) / 1000;
$control = @stream_socket_server('tcp://127.0.0.1:0', $errno2, $errstr2);
if (false === $control) {
    fwrite(\STDERR, "cannot listen: $errstr2\n");
    exit(1);
}
$port = static fn ($s): string => substr((string) strrchr((string) stream_socket_get_name($s, false), ':'), 1);
/** @return resource */
function rawListen(int $port)
{
    $s = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
    if (false === $s) {
        fwrite(\STDERR, "cannot listen on $port: $errstr\n");
        exit(1);
    }

    return $s;
}
$data = null;
if (microtime(true) >= $listenAt) {
    $data = rawListen($dataPort);
    $dataPort = (int) $port($data);
}
echo $dataPort, ' ', $port($control), "\n";
flush();

$mode = 'close';
/** @var array<string, int> $counts */
$counts = [];
/**
 * @var array<int, array{conn: resource, buf: string, state: string, left: int, chunked: bool, served: int, reply: string, keep: bool}> $conns
 */
$conns = [];
/** @var array<int, array{conn: resource, buf: string}> $controls */
$controls = [];

/** @param resource $c */
function rawClose($c, bool $reset): void
{
    if ($reset && function_exists('socket_import_stream')) {
        $sock = @socket_import_stream($c);
        if (false !== $sock) {
            @socket_set_option($sock, \SOL_SOCKET, \SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        }
    }
    @fclose($c);
}

/** The response a `status` or `json` mode gives. */
function rawReply(string $mode): string
{
    $p = explode(' ', $mode, 2);
    if ('json' === $p[0]) {
        $body = $p[1] ?? '{}';

        return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
    }
    [, $code, $after, $reason] = explode(' ', $mode) + [1 => '503', 2 => '-', 3 => '-'];
    $kind = ['429' => 'refused', '409' => 'refused', '502' => 'connection_lost', '503' => 'unreachable', '504' => 'unreachable'][$code] ?? 'protocol';
    $error = ['kind' => $kind, 'message' => "test $code", 'request_id' => 'req_raw'];
    if ('-' !== $reason) {
        $error['reason'] = $reason;
    }
    $body = (string) json_encode(['error' => $error]);

    return "HTTP/1.1 $code Test\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n"
        .('-' !== $after ? "Retry-After: $after\r\n" : '')."Connection: close\r\n\r\n".$body;
}

/** @param resource $c */
function rawWrite($c, string $s): void
{
    stream_set_blocking($c, true);
    @fwrite($c, $s);
    @fflush($c);
    stream_set_blocking($c, false);
}

while (is_resource($control)) {
    if (null === $data && microtime(true) >= $listenAt) {
        $data = rawListen($dataPort);
    }
    $read = null !== $data ? [$data, $control] : [$control];
    foreach ($conns as $c) {
        if ('held' !== $c['state']) {
            $read[] = $c['conn']; // a held socket is not read again: what the client still sends fills its buffers
        }
    }
    foreach ($controls as $c) {
        $read[] = $c['conn'];
    }
    $w = $e = null;
    if (false === @stream_select($read, $w, $e, 0, null === $data ? 10000 : 500000)) {
        continue;
    }
    foreach ($read as $r) {
        if ($r === $data || $r === $control) {
            $c = @stream_socket_accept($r, 0);
            if (false === $c) {
                continue;
            }
            stream_set_blocking($c, false);
            if ($r === $data) {
                $conns[(int) $c] = ['conn' => $c, 'buf' => '', 'state' => 'head', 'left' => 0, 'chunked' => false, 'served' => 0, 'reply' => '', 'keep' => false];
            } else {
                $controls[(int) $c] = ['conn' => $c, 'buf' => ''];
            }
            continue;
        }
        $id = (int) $r;
        $chunk = @fread($r, 1 << 20);
        $eof = false === $chunk || ('' === $chunk && feof($r));
        if (isset($controls[$id])) {
            if ($eof) {
                @fclose($r);
                unset($controls[$id]);
                continue;
            }
            $controls[$id]['buf'] .= $chunk;
            while (false !== ($i = strpos($controls[$id]['buf'], "\n"))) {
                $cmd = trim(substr($controls[$id]['buf'], 0, $i));
                $controls[$id]['buf'] = (string) substr($controls[$id]['buf'], $i + 1);
                if (str_starts_with($cmd, 'mode ')) {
                    $mode = substr($cmd, 5);
                    rawWrite($r, "ok\n");
                } elseif ('counts' === $cmd) {
                    rawWrite($r, json_encode((object) $counts)."\n");
                } else {
                    rawWrite($r, "unknown command\n");
                }
            }
            continue;
        }
        if (!isset($conns[$id])) {
            continue;
        }
        if ($eof) {
            @fclose($r);
            unset($conns[$id]);
            continue;
        }
        $conn = &$conns[$id];
        $conn['buf'] .= $chunk;
        while (true) {
            if ('head' === $conn['state']) {
                $at = strpos($conn['buf'], "\r\n\r\n");
                if (false === $at) {
                    break;
                }
                $head = substr($conn['buf'], 0, $at);
                $conn['buf'] = (string) substr($conn['buf'], $at + 4);
                $method = explode(' ', $head, 2)[0];
                $counts[$method] = ($counts[$method] ?? 0) + 1;
                $len = 0;
                $chunked = false;
                foreach (explode("\r\n", $head) as $line) {
                    if (0 === stripos($line, 'content-length:')) {
                        $len = (int) trim(substr($line, 15));
                    } elseif (0 === stripos($line, 'transfer-encoding:') && false !== stripos($line, 'chunked')) {
                        $chunked = true;
                    }
                }
                $m = explode(' ', $mode, 2)[0];
                if ('keepalive' === $m) {
                    $m = 0 === $conn['served'] ? 'answer' : 'close';
                }
                ++$conn['served'];
                switch ($m) {
                    case 'close':
                    case 'reset':
                        rawClose($r, 'reset' === $m);
                        unset($conns[$id]);
                        break 2;
                    case 'close-body':
                        $conn['state'] = 'body';
                        $conn['left'] = $len;
                        $conn['chunked'] = $chunked;
                        break;
                    case 'answer':
                        // Drain this request's body, then answer and keep the connection.
                        $conn['state'] = 'drain';
                        $conn['left'] = $len;
                        $conn['reply'] = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";
                        $conn['keep'] = true;
                        break;
                    case 'status':
                    case 'json':
                        // Drain the body (closing on unread bytes would reset the connection), answer, close.
                        $conn['state'] = 'drain';
                        $conn['left'] = $len;
                        $conn['reply'] = rawReply($mode);
                        $conn['keep'] = false;
                        break;
                    case 'stall-body':
                        rawWrite($r, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n");
                        $conn['state'] = 'held';
                        break 2;
                    case 'stall-json':
                        rawWrite($r, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\n\r\n{\"desk\":");
                        $conn['state'] = 'held';
                        break 2;
                    case 'stall-events':
                        $ev = "event: stdout\ndata: {\"event\":\"stdout\",\"data\":\"hi\"}\n\n";
                        rawWrite($r, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n".dechex(strlen($ev))."\r\n$ev\r\n");
                        $conn['state'] = 'held';
                        break 2;
                    default: // silent
                        $conn['state'] = 'held';
                        break 2;
                }
            }
            if ('held' === $conn['state']) {
                $conn['buf'] = '';
                break;
            }
            if ('drain' === $conn['state']) {
                $take = min($conn['left'], strlen($conn['buf']));
                $conn['left'] -= $take;
                $conn['buf'] = (string) substr($conn['buf'], $take);
                if ($conn['left'] > 0) {
                    break;
                }
                rawWrite($r, $conn['reply']);
                if (!$conn['keep']) {
                    @fclose($r);
                    unset($conns[$id]);
                    break;
                }
                $conn['state'] = 'head';
                continue;
            }
            // 'body': read the whole request body, then close before any response byte.
            if ($conn['chunked']) {
                if (str_contains($conn['buf'], "\r\n0\r\n\r\n") || str_starts_with($conn['buf'], "0\r\n\r\n")) {
                    rawClose($r, false);
                    unset($conns[$id]);
                    break;
                }
                // Keep only a tail long enough to find the terminator across reads.
                $conn['buf'] = (string) substr($conn['buf'], -16);
                break;
            }
            $take = min($conn['left'], strlen($conn['buf']));
            $conn['left'] -= $take;
            $conn['buf'] = (string) substr($conn['buf'], $take);
            if ($conn['left'] <= 0) {
                rawClose($r, false);
                unset($conns[$id]);
            }
            break;
        }
        unset($conn);
    }
}
