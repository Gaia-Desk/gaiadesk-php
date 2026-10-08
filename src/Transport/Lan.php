<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use GaiaDesk\Exception\FingerprintMismatchException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Exception\UsageException;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\SocketClient;
use GaiaDesk\Internal\Json;
use Psr\Http\Message\RequestInterface;

/**
 * The `lan` transport: a desk's opt-in LAN gateway, `https://<desk>:7443/v1`. Its
 * certificate is self-signed, so the chain and the host name cannot be checked: the
 * SHA-256 of the certificate is pinned instead (the fingerprint the desk shows in
 * Settings), and checked after the TLS handshake and BEFORE any byte of the request is
 * written. The gateway takes agent tokens only, as `X-GaiaDesk-Desk-Token`.
 *
 * @internal
 */
final class Lan
{
    /**
     * A SHA-256 certificate fingerprint as the desk shows it: 32 lowercase hex pairs
     * joined by `:`. Takes it with or without colons (or spaces), any case.
     */
    public static function normalizeFingerprint(mixed $fp): string
    {
        if (!\is_string($fp)) {
            throw new UsageException('fingerprint must be a string (the SHA-256 the desk shows, ab:cd:…)', ['kind' => 'usage']);
        }
        $hex = strtolower((string) preg_replace('/[:\s]/', '', (string) preg_replace('/^sha-?256[:=\s]*/i', '', trim($fp))));
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $hex)) {
            throw new UsageException("fingerprint must be the certificate's SHA-256: 32 hex pairs (ab:cd:…), not ".Json::encode($fp), ['kind' => 'usage']);
        }

        return implode(':', str_split($hex, 2));
    }

    /**
     * The client: TLS to the gateway, its certificate checked against the pin before the request is written.
     *
     * @param array<string, mixed> $ssl extra `ssl` stream context options (a client certificate, ciphers, ...)
     */
    public static function client(string $baseUrl, string $pinned, array $ssl = []): SocketClient
    {
        $parts = parse_url($baseUrl);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? 443);
        $origin = $host.':'.$port;

        return new SocketClient(static function (RequestInterface $r, RequestOptions $o) use ($host, $port, $pinned, $origin, $ssl) {
            $isIp = false !== filter_var($host, \FILTER_VALIDATE_IP);
            $ctx = stream_context_create(['ssl' => $ssl + [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
                'SNI_enabled' => !$isIp,
                'peer_name' => $host,
            ]]);
            $target = 'tls://'.(str_contains($host, ':') ? "[$host]" : $host).':'.$port;
            $conn = @stream_socket_client($target, $errno, $errstr, $o->connectTimeout, \STREAM_CLIENT_CONNECT, $ctx);
            if (false === $conn) {
                // Never connected, so nothing was sent; unless connecting timed out (a timeout is never retried).
                $timedOut = \in_array($errno, [60, 110, 10060], true) || false !== stripos((string) $errstr, 'timed out');

                throw new NetworkException($r, "the desk's LAN gateway ($origin) could not be reached: ".('' !== $errstr ? $errstr : "error $errno"), $timedOut, !$timedOut, null, false, $timedOut ? 'connectTimeout' : null);
            }
            $params = stream_context_get_params($conn);
            $ssl = \is_array($params['options']['ssl'] ?? null) ? $params['options']['ssl'] : [];
            $cert = $ssl['peer_certificate'] ?? null;
            $actual = '';
            if ($cert instanceof \OpenSSLCertificate) {
                $fp = openssl_x509_fingerprint($cert, 'sha256');
                $actual = false !== $fp ? self::normalizeFingerprint($fp) : '';
            }
            if (!hash_equals($pinned, $actual)) {
                fclose($conn);
                throw new FingerprintMismatchException(
                    "the desk at $origin did not prove the pinned identity: its certificate's SHA-256 is ".('' !== $actual ? $actual : '(none)').", not $pinned. Do not proceed: this may not be your desk. Check the fingerprint in its Settings → GaiaDesk API.",
                    $pinned,
                    $actual,
                    ['kind' => 'unreachable', 'reason' => 'fingerprint_mismatch', 'exitCode' => 255],
                );
            }

            return $conn;
        });
    }

    public static function unreachable(string $origin, string $why): UnreachableException
    {
        return new UnreachableException("the desk's LAN gateway ($origin) could not be reached: $why", ['kind' => 'network', 'reason' => 'network', 'exitCode' => 255]);
    }
}
