# Changelog

All notable changes to this package. It follows [Semantic Versioning](https://semver.org).

## 0.1.1

Never hang on a dropped or stalled connection.

- **Timeouts**: `responseTimeout` (default 16 minutes, above the API's 15-minute call limit)
  bounds the wait for an answer to begin, sending the request included; `idleTimeout` (default
  90 s; streams and held waits keep alive every 15 s) bounds every read of its body (JSON, error
  bodies, downloads plain and sealed, event streams). Both on `new GaiaDesk(...)`,
  `GaiaDesk::local()` and `GaiaDesk::lan()`, in seconds, `null` for no limit. Exceeded:
  `UnreachableException` / `ConnectionLostException`, kind `timeout`, the message naming the
  limit; a stream ends with `connection_lost`, reason `timeout`, exit 255. Neither is retried.
- Before: a server that stopped reading an upload held the LAN and named-pipe client forever (a
  blocking write); a silent server or a stalled body was only cut off by curl's 120-second
  low-speed limit or an operation's total limit (up to 17 minutes for `exec`), and then as an
  `UnreachableException` even when the answer had begun (one that broke off was even retried as a
  GET); the stream client also took a silent answer for a closed connection. The curl
  client now keeps both limits in the loop that drives it (not `CURLOPT_LOW_SPEED_*`, which also
  ran while the answer was awaited), the stream client writes without blocking.
- An answer that breaks off after it began is a `ConnectionLostException` (`incomplete`), never
  retried; a stream that breaks off ends with `connection_lost`. The last event before a stream
  broke off is no longer lost.
- Requests other than GET and HEAD always go on a fresh, never-reused curl connection, so libcurl
  cannot silently re-send one (it does, body and all, on a reused connection that was closed
  before any answer), even with a shared connection cache passed in `curlOptions`.
- A request's curl connection now closes as soon as the request ends. Since PHP 8,
  `curl_multi_close()` does nothing, and the transfer's callbacks kept its handles in a cycle only
  the garbage collector broke: failed requests left their sockets open (about four descriptors
  each) until a collection ran.
- A plain PSR-18 client's body that is a PHP stream is read under `idleTimeout`.
- Tests: a raw TCP server (no HTTP framework) that closes or resets before any response byte
  (with and without reading the body), stalls mid-body, mid-JSON and mid-stream, or never
  answers; a 300-request stress run; and the libcurl re-send pinned.

## 0.1.0

The first release: the GaiaDesk /v1 API from PHP, at parity with the TypeScript SDK's API
transport, and covering the API routes that SDK leaves out.

- **Desk operations**: `exec` and `execStream` (with `shell`, `timeout`, `cwd`, `stdin`, `env`,
  `check`, and `admin` to run as root or SYSTEM); jobs (`runJob`, `jobs`, `jobLogs`,
  `followJobLogs`, `waitJob`, `killJob`); files (`upload`, `uploadFrom`, `download`, `downloadTo`,
  `downloadBytes`, streamed both ways); `stats`; agent tokens (`createToken` with the `admin`
  scope, `listTokens`, `revokeToken`).
- **Hosted API**: `devices`, `desk`, `reach`, `wake`, `auditEvents` and `iterateAuditEvents`
  (paging by `until_ms`), webhooks (`createWebhook`, `webhooks`, `deleteWebhook`, and
  `Webhooks::verify`, `parse` and `sign` for deliveries), support sessions
  (`createSupportSession`, `supportSessions`, `supportSession`).
- **End-to-end encryption** of desk operations (X25519, HKDF-SHA256, XChaCha20-Poly1305 via
  ext-sodium). It reproduces the protocol's shared test vectors byte for byte. Options:
  `e2e: auto | require | off`, `e2eKeys` (pinned keys) and `onWarning`. Retries once on
  `e2e_required` and on a rotated key. New `E2eException`.
- **Typed exceptions** from the API's error envelope: `UsageException`, `RefusedException`,
  `UnreachableException`, `ConnectionLostException`, `OperationFailedException`,
  `ProtocolException`, `CommandException`, `FingerprintMismatchException`. Each carries the kind,
  reason, desk, HTTP status, request id, Retry-After and exit code.
- **Retries** that are safe by construction: 429s (following Retry-After), network errors on GETs
  and on idempotent calls, 502 and 504 on GETs. Calls that create things take `idempotencyKey`.
- **HTTP**: a PSR-18 client of the SDK's own over ext-curl that streams Server-Sent Events and file
  bodies as they arrive and enforces per-request limits. Any PSR-18 client can be passed instead.
- **`local` transport**: the desk's own API over its Unix socket (curl) or Windows named pipe (raw
  HTTP/1.1). **`lan` transport**: a desk's LAN gateway over TLS, its certificate pinned by SHA-256
  before the request is written.
- Result shapes for PHPStan and Psalm (`GaiaDesk\Types`), generated from the API contract by
  `tools/gen-types.php`.
- Requires PHP 8.1+ with ext-curl, ext-sodium, ext-openssl, ext-mbstring and ext-json. Runtime
  dependencies: `nyholm/psr7`, `psr/http-client`, `psr/http-factory`, `psr/http-message` (all MIT).
