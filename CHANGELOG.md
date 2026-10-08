# Changelog

All notable changes to this package. It follows [Semantic Versioning](https://semver.org).

## 0.1.0 (unreleased)

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
