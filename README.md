# GaiaDesk SDK for PHP

The official PHP SDK for the [GaiaDesk](https://gaiadesk.net) API. Use it to run commands on your
desks, stream their output, run background jobs, move files, mint scoped agent tokens and read
desk stats. It also covers the fleet (listing desks, reachability, wake), the audit trail, webhooks
and support sessions. Desk operations are **end-to-end encrypted** whenever the desk publishes a
key: the hosted API relays only ciphertext.

```php
$gd = new GaiaDesk\GaiaDesk(apiKey: getenv('GAIADESK_API_KEY'), deskToken: getenv('GAIADESK_DESK_TOKEN'));

$r = $gd->exec('123456789', 'uname -a');
echo $r['stdout'];
```

- PHP 8.1 or newer, with `ext-curl`, `ext-sodium`, `ext-openssl`, `ext-mbstring` and `ext-json`
  (all of these ship with standard PHP builds)
- PSR-7, PSR-17 and PSR-18. It comes with its own streaming curl client, or you can pass any PSR-18
  client
- Results are the API's JSON objects as PHP arrays, with their shapes declared for PHPStan and
  Psalm in [`GaiaDesk\Types`](src/Types.php)
- MIT licensed

## Contents

- [Install](#install)
- [Credentials](#credentials)
- [Commands](#commands): `exec`, `execStream`, administrator commands
- [Background jobs](#background-jobs): `runJob`, `jobs`, `jobLogs`, `followJobLogs`, `waitJob`, `killJob`
- [Files](#files): `upload`, `uploadFrom`, `download`, `downloadTo`, `downloadBytes`
- [Stats](#stats)
- [Agent tokens](#agent-tokens): `createToken`, `listTokens`, `revokeToken`
- [Fleet](#fleet): `devices`, `desk`, `reach`, `wake`
- [Audit trail](#audit-trail): `auditEvents`, `iterateAuditEvents`
- [Webhooks](#webhooks): `createWebhook`, `webhooks`, `deleteWebhook`, `Webhooks::verify`
- [Support sessions](#support-sessions)
- [End-to-end encryption](#end-to-end-encryption)
- [Errors](#errors)
- [Retries, timeouts and idempotency](#retries-timeouts-and-idempotency)
- [HTTP clients and streaming](#http-clients-and-streaming)
- [Local and LAN transports](#local-and-lan-transports)
- [Coverage of the API](#coverage-of-the-api)
- [Development](#development)

## Install

```sh
composer require gaiadesk/gaiadesk
```

## Credentials

| Credential | Header | What it does |
|---|---|---|
| Atlas API key, `ak_…` (`apiKey:`) | `Authorization: Bearer` | Calls the API with exactly the scopes on the key (`desks:read`, `desks:write`, `exec`, `files`, `jobs`, `tokens`, `audit:read`, `webhooks`, `support`) |
| A person's session or OAuth token (`apiKey:`) | `Authorization: Bearer` | Acts as that person on their own desks and their team's |
| Scoped agent token, `gdagt_…` (`deskToken:`) | `X-GaiaDesk-Desk-Token` | Needed for desk operations made with an API key. The **desk** checks it, along with its scopes, `cwd` confinement and low-privilege user |

You can pass a different agent token for a single call with `deskToken:`. Any desk operation also
takes `wake:` (0 to 120 seconds): if the desk is asleep, the API rings it and waits up to that long
before giving up.

```php
use GaiaDesk\GaiaDesk;

$gd = new GaiaDesk(
    apiKey: getenv('GAIADESK_API_KEY'),
    deskToken: getenv('GAIADESK_DESK_TOKEN'),
);
$gd->stats('123456789', deskToken: 'gdagt_other', wake: 30);
```

## Commands

```php
$r = $gd->exec('123456789', 'make test', timeout: '10m', cwd: 'src/app', env: ['CI' => '1']);
$r['exit'];       // the command's exit code (124: its timeout ran out)
$r['stdout'];     // and stderr, timed_out, truncated (output over 8 MB), duration_ms, ...
```

- A **string** command is one command line, given to the desk's shell exactly as written. An
  **array** is a list of separate arguments: `['ls', '-l', $dir]`. The desk quotes each argument
  for its shell, or runs the program directly with `shell: 'none'`.
- Optional arguments:
  - `shell:` is one of `default`, `none`, `sh`, `bash`, `zsh`, `cmd`, `pwsh` or `powershell`.
    `powershell` is sent as `pwsh`.
  - `timeout:` is a number of seconds or a duration such as `30s`, `10m` or `1h30m`. Over the API a
    command runs for at most 15 minutes; start a job for anything longer.
  - `stdin:` is text written to the command's input before it is closed.
  - `cwd:` and `env:` set the working directory and environment variables.
  - `idempotencyKey:` is described under [Retries](#retries-timeouts-and-idempotency).
- A command that **ran** returns its result whatever its exit code. With `check: true`, a non-zero
  exit or a timeout throws `CommandException`, which carries the whole result in `getResult()`.
- A command the desk **would not start** is thrown as its typed exception. The usual cases are a
  refused token, a path outside a confined token's folder, and administrator refusals.

### Streaming output

```php
$s = $gd->execStream('123456789', ['make', 'test']);
foreach ($s as $chunk) {                 // GaiaDesk\Stream\Chunk: ->stream ('stdout'|'stderr'), ->data
    echo $chunk->data;
}
$exit = $s->wait();                      // GaiaDesk\Stream\StreamExit: ->exitCode, ->result, ->error, ->ok()
```

- `$s->text()` yields `['stream' => …, 'text' => …]`. A UTF-8 character split across two chunks
  is held back until it is whole.
- `$s->cancel()` hangs up. The server stops the command and `wait()` returns exit code 130.
  Calling it inside the `foreach` loop is safe.
- If the stream could not start (refused, unreachable, …), nothing is thrown. Instead `wait()`
  returns how it ended, with the error in `->error` and `->exitCode` set to 254 (refused) or 255.
- A stream can be iterated only once. `wait()` reads anything you have not iterated, discards that
  output, and returns how the command ended.
- **stdin** is text you pass up front (`stdin: '…'`). The HTTP API cannot take input while the
  command runs, so `$s->write()` throws a `UsageException`. Use `gaiadesk-cli` for interactive
  input.

### As administrator

`admin: true` runs the command as **root** (macOS, Linux) or **SYSTEM** (Windows), inside the desk's
privileged GaiaDesk process. Two things must both be true:

- The agent token was minted with the `admin` scope. That scope is never implied, so you have to
  name it.
- The desk owner has turned on **Admin access** at the desk itself. Turning it on needs the
  computer's administrator password, and no API call can do it.

In its default mode the desk asks the person sitting at it each time. A refusal is a
`RefusedException` with exit code 254, and `getReason()` is one of `admin_scope_missing`,
`admin_not_enabled`, `admin_denied` or `admin_unavailable`.

```php
try {
    echo $gd->exec($desk, 'id', admin: true)['stdout'];
} catch (GaiaDesk\Exception\RefusedException $e) {
    echo "not as administrator: {$e->getReason()}\n";
}
```

## Background jobs

```php
$job = $gd->runJob($desk, 'nightly', './build.sh --release',
    priority: 'low', cpu: 50, mem: '2G', keepAwake: true, shell: 'bash', env: ['CI' => '1']);

$gd->jobs($desk);                                   // list<Job>
echo $gd->jobLogs($desk, 'nightly', tail: 4096);    // the last 4 KiB of output
foreach ($gd->followJobLogs($desk, 'nightly') as $chunk) { echo $chunk->data; }   // until it ends
$r = $gd->waitJob($desk, 'nightly', timeout: '2h'); // ['job' => …, 'timed_out' => bool]
$gd->killJob($desk, 'nightly');
```

`waitJob` with no timeout waits until the job ends. The API holds a single wait for at most 870
seconds, so the SDK sends a new wait each time one runs out. If a wait answer was already under way
when the desk failed, that failure is thrown as its typed exception.

## Files

Each file is at most 256 MB through the API. Both directions are streamed, so a file is never held
in memory whole. A sealed download is opened piece by piece as it arrives.

```php
$gd->upload('/tmp/report.csv', $desk, 'inbox/');            // a remote path ending in / keeps the file's name
$gd->uploadFrom("text\n", $desk, 'notes/a.txt');            // a string, a stream resource, or a PSR-7 stream
$gd->download($desk, 'inbox/report.csv', '/tmp/');          // the file appears only once it is whole
$gd->downloadTo($desk, 'logs/app.log', fopen('php://stdout', 'wb'));
$bytes = $gd->downloadBytes($desk, 'notes/a.txt');
```

If a download breaks off partway, the SDK throws `ConnectionLostException` with reason
`incomplete`. You never get a short file that looks complete. For folders and larger files, use
`gaiadesk-cli cp`, which talks to the desk directly and resumes interrupted transfers. Over the HTTP
API a transfer is a single streamed request.

## Stats

```php
$s = $gd->stats($desk);   // hostname, os, cpu_percent, cpus, mem_total_mb, mem_free_mb, disks, load, jobs_running, ...
```

## Agent tokens

Only the desk's **owner** can manage its tokens, using their own session token as `apiKey`. An agent
token cannot manage tokens, whatever its scopes.

```php
$owner = new GaiaDesk(apiKey: $personSessionToken);
$m = $owner->createToken(['123456789', '987654321'], 'ci-bot',
    expires: '30d', scopes: ['exec', 'cp', 'jobs'], cwd: '/srv/app', lowPriv: false);
$m['tokens'][0]['secret'];            // gdagt_… : shown once
$owner->listTokens('123456789');
$owner->revokeToken('123456789', 'ci-bot');   // by id or name
```

- With several desks, the SDK mints one token per desk. If a later desk fails, the exception's
  `getJson()['tokens']` holds the tokens already minted, so their secrets are not lost.
- Scopes are any of `exec`, `shell`, `cp`, `forward`, `jobs`, `screen` and `admin`. The default is
  `exec`, `cp`, `jobs`. `admin` is never implied, and a confined token (`cwd:` or `lowPriv:`) cannot
  carry it.

## Fleet

```php
$gd->devices();                           // every desk on the account and team, online first
$gd->desk('123456789');                   // one desk, with its e2e_pub and wake hints
$gd->reach('123456789', limit: 50);       // its online/offline history
$gd->wake('123456789', waitS: 30);        // ring the doorbell and Wake-on-LAN; ['woke' => bool, ...]
```

## Audit trail

```php
$gd->auditEvents(desk: '123456789', action: 'api.*', limit: 100);   // one page, newest first

foreach ($gd->iterateAuditEvents(action: 'api.*', sinceMs: $since, pageSize: 500) as $event) {
    // every matching event, fetched a page at a time
}
```

The API has no cursor. `iterateAuditEvents` pages backwards using `until_ms` and skips any event it
has already yielded, so nothing is repeated or lost where several events share a millisecond.
`max:` stops it early.

## Webhooks

```php
$w = $gd->createWebhook('https://example.com/hooks/gaiadesk', ['desk.online', 'desk.offline', 'job.finished']);
$secret = $w['secret'];                   // whsec_… : shown once
$gd->webhooks();
$gd->deleteWebhook($w['id']);
```

On your endpoint, check every delivery's signature against the **raw** request body:

```php
use GaiaDesk\Webhooks;

$event = Webhooks::parse($secret, $_SERVER['HTTP_GAIADESK_SIGNATURE'] ?? '', file_get_contents('php://input'));
// RefusedException (webhook_signature_invalid) when it is not signed with $secret, or its t is > 5 minutes off.
// Delivery is at least once: de-duplicate by $event['id'].
```

`Webhooks::verify()` returns a bool instead of throwing. `Webhooks::sign()` produces a signature, so
you can test your own endpoint.

## Support sessions

These are for the embed SDKs (web: `@gaiadesk/embed`; desktop: the native embed). They need a key
with the `support` scope.

```php
$s = $gd->createSupportSession(mode: 'cobrowse', customer: ['name' => 'Ada', 'plan' => 'pro'],
    expiresIn: 1800, origin: 'https://app.example.com');
$s['embed_token'];                        // gdemb_… : hand it to the page; shown once
$s['join_code']; $s['join_url'];          // your agents join with these
$gd->supportSessions(state: 'all');
$gd->supportSession($s['id']);
```

## End-to-end encryption

Desk operations go through the hosted API: exec, streams, jobs, logs, waits, files, tokens and
stats. When the desk publishes a key, the SDK **seals** each of these operations to the desk. The
server then relays ciphertext only. It never sees the command, its environment, stdin, a file's name
or bytes, or any output.

What the server still sees:

- the API key and the agent token
- the route, which reveals the operation and the desk
- `stream`, `follow` and `wake_s`
- sizes
- how the operation ended: its exit code, or an error's kind and reason

Results, stream events and errors are exactly the same as for the plaintext call.

How it works, per operation (`protocol/src/e2e.rs` in GaiaDesk; reproduced byte for byte from its
shared test vectors in `tests/fixtures/e2e-vectors.json`):

1. The SDK makes a fresh **X25519** key pair, and derives a shared secret with the desk's `e2e_pub`
   (published on `GET /desks/{id}` while the desk is online).
2. **HKDF-SHA256** with the salt `gaiadesk desk-op e2e v1` derives one key each for the request,
   the input and the events.
3. Every message is **XChaCha20-Poly1305** with a random 24-byte nonce. Its associated data names
   the use, the desk and the operation, and for streams the message's sequence number. A message
   that is altered, reordered, replayed, or sealed for another desk or operation does not open.

The cryptography comes from libsodium (`ext-sodium`) and PHP's `hash_hkdf`. Nothing is home-made.

```php
$gd = new GaiaDesk(apiKey: $key, deskToken: $token,
    e2e: 'require',                                   // 'auto' (default) | 'require' | 'off'
    e2eKeys: ['123456789' => 'B6N8vBQgk8i3VdwbEOhstCY3StFqqFPtC9_AsrhtHHw'],  // pin the desk's key
    onWarning: fn (string $m) => $logger->warning($m));
```

**`e2e` modes:**

| Mode | Behaviour |
|---|---|
| `auto` (default) | Seals whenever the desk lists a key. Without a key, it sends in the clear and warns once per desk through `onWarning` (default: `error_log`). A desk whose owner requires encryption is always sealed. |
| `require` | Never sends in the clear. A desk with no key is woken and asked again. If there is still no key, the SDK throws `E2eException` (`e2e_unavailable`) and nothing is sent. |
| `off` | Never seals. |

**Pinned keys:** if the server hands out a different key from the one you pinned, the SDK throws
`E2eException` (`e2e_key_mismatch`) before anything is sent. While the desk lists no key (for
example, when it is offline), the pinned key is used to seal.

**Automatic retries:**

- If the API refuses a plaintext call because the desk requires encryption (`e2e_required`), the
  SDK fetches the key again and sends the call sealed, once.
- If the desk cannot open a sealed call because its key has rotated (`e2e_decrypt_failed`), the SDK
  fetches the new key and seals the call again, once.

**Reading the key needs the `desks:read` scope.** Without it, `auto` falls back to plaintext with a
warning. To avoid that, pin the key or use `require`.

**Hostile server:** an answer that does not open, or plaintext output inside a sealed stream, is a
`ProtocolException` (`e2e_decrypt_failed`, `e2e_unsealed_answer`). Forged output is never passed
to you.

The local and LAN transports never leave the desk or your LAN, so they do not seal.

## Errors

Every failure is a `GaiaDesk\Exception\GaiaDeskException`, and the API's error envelope
`{"error": {"kind", "message", "reason", "desk", "request_id"}}` decides which class.

| Class | `kind` | HTTP | Typical `reason` |
|---|---|---|---|
| `UsageException` | `usage` | 400 | `bad_body`, `idempotency_key_reused`; also thrown by the SDK itself before anything is sent |
| `RefusedException` | `refused` | 401, 403, 429 | `unauthenticated`, `missing_scope`, `desk_token_required`, `rate_limited`, `desk_busy`, `desk_opted_out`, `e2e_required`, `admin_*` |
| `E2eException` (a `RefusedException`) | `refused` | none (not sent) | `e2e_unavailable`, `e2e_key_mismatch` |
| `UnreachableException` | `unreachable`, or finer: `offline`, `unknown_desk`, `network`, `timeout` | 404, 409, 503, 504 | `unknown_desk`, `offline`, `silent`, `no_wake_path`, `network`, `local_api_unavailable` |
| `FingerprintMismatchException` (an `UnreachableException`) | `unreachable` | none (not sent) | `fingerprint_mismatch` |
| `ConnectionLostException` | `connection_lost` | 502 | `desk_disconnected`, `incomplete` |
| `OperationFailedException` | `failed` | 422 | `not_found`, and a missing job |
| `ProtocolException` | `protocol` | 409, 502, … | `desk_too_old`, `e2e_unsupported`, `e2e_decrypt_failed`, and an answer that is not JSON or carries no error envelope |
| `CommandException` | `failed` | none | `exec(..., check: true)` and the command exited non-zero |

Every exception carries the same details:

| Method | What it returns |
|---|---|
| `getKind()` | The finest kind known. For example `offline` stays `offline`. |
| `getReason()` | The finer cause, such as `rate_limited` or `admin_denied`. |
| `getDesk()` | The desk the error concerns. |
| `getStatus()` | The HTTP status. |
| `getRequestId()` | `req_…`; quote it to support. |
| `getRetryAfter()` | Seconds to wait, from the 429's `Retry-After`. |
| `getExitCode()` / `getCode()` | gaiadesk-cli's exit code for the same failure: 254 refused, 1 failed, 130 interrupted, 255 the rest. |
| `getJson()` | The envelope itself. |
| `getArgv()` | The call, for example `POST /desks/…/exec`. |
| `getBody()` | The start of a body that was not an envelope. |

```php
try {
    $gd->exec($desk, 'deploy');
} catch (GaiaDesk\Exception\UnreachableException $e) {
    // offline / unknown_desk / network ...
} catch (GaiaDesk\Exception\RefusedException $e) {
    if ('rate_limited' === $e->getReason()) { sleep((int) ceil($e->getRetryAfter() ?? 1)); }
} catch (GaiaDesk\Exception\GaiaDeskException $e) {
    error_log("{$e->getMessage()} ({$e->getRequestId()})");
}
```

## Retries, timeouts and idempotency

The SDK retries a request only when it is safe to. `maxRetries` (default 2) sets how many times;
pass `maxRetries: 0` to turn retries off. These are retried:

- **429** (`rate_limited`, `desk_busy`), for any method, since nothing ran. The SDK waits for the
  `Retry-After` time, but gives up rather than wait more than 60 seconds.
- `idempotency_key_in_flight`
- A **network error** (the connection closed or reset before any answer) on a GET, on any call
  that has an `idempotencyKey`, or when the connection was never made
- A **502 or 504 on a GET**

A timeout is never retried, nor is an answer that broke off after it began.

Waits between retries use exponential backoff with jitter. Each retry of a sealed operation is
sealed afresh. Streams are never retried once their answer has started.

**Idempotency:** `exec`, `runJob`, `createToken`, `wake`, `createWebhook` and
`createSupportSession` take `idempotencyKey:`. If you retry with the same key within 24 hours, the
API returns the first answer again, and the SDK will then also retry that call after a network
error.

**Timeouts** make a server or proxy that stops answering an error, never a hang. Two limits
apply to every request, on every transport (`api`, `local`, `lan`):

- `responseTimeout:` (seconds, default 960 = 16 minutes, above the API's 15-minute limit on a
  call): the longest wait for an answer to begin (its status and headers), sending the request
  included. Exceeded: `UnreachableException`, kind `timeout`, its message naming
  `responseTimeout`.
- `idleTimeout:` (seconds, default 90; the API's streams and held waits send a keep-alive every
  15 s): the longest silence while reading an answer's body (JSON, a download, an event stream).
  It limits each wait for more bytes, never the body as a whole: a large download that keeps
  flowing never times out. Exceeded: `ConnectionLostException`, kind `timeout`; a stream ends
  with that error in its `StreamExit` (`error['kind']` `connection_lost`, reason `timeout`, exit
  255).
- `null` turns either limit off. Zero, a negative number, `INF` or `NAN` is a `UsageException`.
- A connection closed or reset before any answer is an `UnreachableException` (kind `network`)
  at once. libcurl itself re-sends a request, body and all, when a connection it **reused** turns
  out to have been closed before any answer; the SDK's curl client opens a connection per request
  (and forces a fresh, never-reused one for anything but GET and HEAD, even with a shared
  connection cache in `curlOptions`), and the LAN and named-pipe client sends one request per
  connection, so `exec`, uploads, jobs, tokens and wakes go at most once unless the retry rules
  above allow.
- A connection that times out is closed, never reused. A download to a file that fails leaves no
  file behind.

Each operation also sets its own total limit where it has one: a command allows its `timeout`
(default 15 minutes) plus the wake time, and waits allow their timeout. You can also set:

- `timeout:` the total limit for every request that is not streamed.
- `connectTimeout:` (default 30 s) limits how long connecting may take.

On the Windows named pipe (the `local` transport there), PHP cannot wait with a limit: reads and
writes on it block. The pipe is the desk's own app, on the same machine.

## HTTP clients and streaming

By default the SDK sends requests with its own **curl client** (`GaiaDesk\Http\CurlClient`, a
PSR-18 client). It reads response bodies as they arrive, so Server-Sent Events stream live and
downloads are written straight to their destination. It sends uploads from a stream, enforces
per-request limits, and speaks HTTP over a Unix socket. To add a proxy, a CA bundle or any other
`CURLOPT_*` setting, pass your own instance:
`httpClient: new GaiaDesk\Http\CurlClient(curlOptions: [CURLOPT_PROXY => 'http://proxy:3128'])`.

You can pass any **PSR-18** client instead, as `httpClient:` (with `requestFactory:` and
`streamFactory:` for your own PSR-17 factories; the default factories are `nyholm/psr7`'s):

```php
$gd = new GaiaDesk(apiKey: $key, httpClient: new Symfony\Component\HttpClient\Psr18Client());
```

A plain PSR-18 client gives you the same results. Whether streams arrive **live** depends on the
client:

| Client | Streaming |
|---|---|
| Symfony's `Psr18Client` | Streams response bodies. |
| Guzzle | Streams only when created with `['stream' => true]`. |
| Any other client that reads the whole body first | `execStream` and `followJobLogs` still work, but every event arrives together at the end, and downloads are buffered by that client. |

A plain PSR-18 client applies its own timeouts to sending the request and waiting for the answer:
`responseTimeout` and the per-operation limits only take effect with a client that implements
`GaiaDesk\Http\TransportClient`, so configure yours (Guzzle: `timeout` and `read_timeout`;
Symfony: `timeout`, which is an idle limit, and `max_duration`). The SDK does bound the reads it
makes itself: a body handed over as a PHP stream (Guzzle's and `nyholm/psr7`'s streams) is read
under `idleTimeout`. A body that is not a PHP stream (Symfony's, for one) is read as the client
gives it, under that client's own limits. Whatever the client throws becomes the SDK's typed
error.

## Local and LAN transports

These run the same desk operations (with the same methods, results and errors) without the hosted
API.

**On the desk itself.** The GaiaDesk app serves its own API:

- on macOS and Linux, on the Unix socket `~/.gaiadesk/api.sock`, through curl's
  `CURLOPT_UNIX_SOCKET_PATH`
- on Windows, on the named pipe `\\.\pipe\gaiadesk-api-<user>`, opened as a file with raw HTTP/1.1
  written on it, because curl cannot open named pipes

The credential is the desk's local admin token (read from `~/.gaiadesk/api-token` on each request)
or an agent token. `GAIADESK_API_DIR` and `GAIADESK_API_PIPE` move the socket and the pipe.

```php
$here = GaiaDesk::local();                       // or GaiaDesk::local(deskToken: 'gdagt_…')
$here->exec('123456789', 'hostname');
```

**From the same LAN.** A desk's opt-in gateway serves the API at `https://<desk>:7443/v1`. Its
certificate is self-signed, so the SDK **pins its SHA-256 fingerprint**, the one shown in the desk's
Settings. The fingerprint is checked after the TLS handshake and **before any byte of the request is
written**. A mismatch throws `FingerprintMismatchException`. The gateway accepts agent tokens only.

```php
$lan = GaiaDesk::lan('https://gaiadesk-123456789.local:7443/v1', 'ab:cd:…', deskToken: 'gdagt_…');
```

The fleet, wake, audit, webhook and support-session calls belong to the hosted API only. On the
local and LAN transports they throw a `UsageException` that says so.

**Limits:**

- On the local and LAN transports, a stream or a held wait on the Windows pipe cannot time out,
  because PHP cannot put a time limit on reading a pipe opened as a file.
- The named-pipe road is tested with the same raw HTTP client over TCP, not against a real Windows
  desk.

## Coverage of the API

| Endpoint | Method |
|---|---|
| `GET /desks` | `devices()` |
| `GET /desks/{id}` | `desk()` (also how end-to-end encryption finds a desk's key) |
| `GET /desks/{id}/reach` | `reach()` |
| `POST /desks/{id}/wake` | `wake()` |
| `GET /audit` | `auditEvents()`, `iterateAuditEvents()` |
| `GET` / `POST /webhooks`, `DELETE /webhooks/{id}` | `webhooks()`, `createWebhook()`, `deleteWebhook()`; deliveries: `Webhooks::verify()`, `parse()` |
| `POST` / `GET /support/sessions`, `GET /support/sessions/{id}` | `createSupportSession()`, `supportSessions()`, `supportSession()` |
| `POST /desks/{id}/exec` (`?stream=1`) | `exec()`, `execStream()` |
| `POST` / `GET /desks/{id}/jobs` | `runJob()`, `jobs()` |
| `DELETE /desks/{id}/jobs/{name}` | `killJob()` |
| `GET /desks/{id}/jobs/{name}/logs` (`?follow=1`) | `jobLogs()`, `followJobLogs()` |
| `GET /desks/{id}/jobs/{name}/wait` | `waitJob()` |
| `GET /desks/{id}/stats` | `stats()` |
| `PUT` / `GET /desks/{id}/files` | `upload()`, `uploadFrom()`, `download()`, `downloadTo()`, `downloadBytes()` |
| `POST` / `GET /desks/{id}/tokens`, `DELETE /desks/{id}/tokens/{token_id}` | `createToken()`, `listTokens()`, `revokeToken()` |

Some things are **not** available over the HTTP API, so this SDK does not offer them: interactive
shells, port forwarding, MCP, recursive (folder) copies, resumable transfers, and files over 256 MB.
Use `gaiadesk-cli`, or the TypeScript or Python SDK's CLI or native transport, for those.

## Development

```sh
composer install
composer test        # PHPUnit: unit tests, the shared e2e vectors, every endpoint over a mock PSR-18 client, a real HTTP server (TCP, Unix socket, pinned TLS), and a raw TCP server that drops and stalls connections
composer analyse     # PHPStan: src and examples at level max, tests at level 6
composer cs          # PHP-CS-Fixer (PER-CS 2.0 + Symfony)
composer types -- path/to/openapi.json > src/Types.php   # regenerate the result shapes from the API contract
```
