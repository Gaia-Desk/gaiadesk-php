# Changelog

All notable changes to this package. It follows [Semantic Versioning](https://semver.org).

## 0.1.2

**Breaking: administrator work is not available over any API.** The hosted API, a desk's local API
and its LAN gateway refuse it with `admin_not_via_api`; run it with `gaiadesk-cli exec --admin`.

- Removed: `exec(..., admin:)` and `execStream(..., admin:)`, the `admin` scope from
  `GaiaDesk::TOKEN_SCOPES` (and the SDK's check that a confined token cannot carry it), the
  `admin_*` refusal reasons in the docs (`admin_scope_missing`, `admin_not_enabled`,
  `admin_denied`, `admin_unavailable`) and `examples/admin.php`. No aliases.
- `admin_not_via_api` is a `RefusedException` (kind `refused`, exit 254): from `exec` (200 with
  exit 254), as a stream's exit, and from `createToken` with the `admin` scope (403).
- Types: `ApiExecSpec` (no administrator work) and `ApiMintSpec` (no `admin` scope), regenerated
  from the API contract; `tools/gen-types.php` now applies a narrowing `allOf` part (a `const`,
  an `enum`, an array's items).
- Fixed: static analysis on PHP 8.1 and 8.2 (`CURLOPT_PROTOCOLS_STR`, from PHP 8.3, is now read
  by name). Tests: Windows path separators and Windows' slower refusal of a loopback connect.

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
- **Retries: one policy in every GaiaDesk SDK.** A request is sent again only when that cannot
  run anything twice: a connection never made (any method), a connection lost after sending or a
  502/503/504 (GETs only), a 429 or a 409 `idempotency_key_in_flight` (any method). Changes:
  - Now retried: a **503 on a GET** (unless its reason is `api_disabled`, `desk_ops_disabled` or
    `local_api_off`), waiting for its `Retry-After`; 502/503/504 on a **streamed GET**
    (`followJobLogs`, downloads) before its answer begins; a connection that **never got made**
    through a broken TLS handshake (curl, LAN) or because the **local API's socket or pipe** was
    not there; the 409 retry now needs status 409.
  - No longer retried: a call with an `idempotencyKey` after a network error (closed or reset before
    any answer): the key is sent but never unlocks a retry; a LAN connect that **timed out**.
  - Backoff: 250 ms doubling up to 8 s, times a random 0.5–1.0 (was 0.5 s doubling up to 8 s, times
    0.75–1.25). New options `retryBaseDelay` (0.25), `retryMaxDelay` (8.0) and `maxRetryWait`
    (60.0, the longest `Retry-After` waited for; longer: the error at once, carrying it), on
    `new GaiaDesk(...)`, `GaiaDesk::local()` and `GaiaDesk::lan()`; `maxRetries` stays (default 2).
  - libcurl re-sends a bodiless DELETE as readily as a PUT on a reused connection: every non-GET
    goes on a fresh connection (pinned for DELETE, POST and PUT).
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
