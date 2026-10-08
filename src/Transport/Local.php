<?php

declare(strict_types=1);

namespace GaiaDesk\Transport;

use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\Exception\UnreachableException;
use GaiaDesk\Http\CurlClient;
use GaiaDesk\Http\NetworkException;
use GaiaDesk\Http\RequestOptions;
use GaiaDesk\Http\SocketClient;
use GaiaDesk\Http\TransportClient;
use Psr\Http\Message\RequestInterface;

/**
 * The `local` transport's places: code running ON the desk talks to the GaiaDesk app's
 * own /v1 API over a Unix socket (macOS, Linux) or a named pipe (Windows).
 *
 *   socket  `$GAIADESK_API_DIR/api.sock`, else `~/.gaiadesk/api.sock`
 *   pipe    `$GAIADESK_API_PIPE`, else `\\.\pipe\gaiadesk-api-<user>`
 *   token   an agent token as `X-GaiaDesk-Desk-Token`, else the desk's local admin token
 *           (`gdlocal_…`, `$GAIADESK_API_DIR/api-token`, else `~/.gaiadesk/api-token`) as
 *           `Authorization: Bearer`
 *
 * @internal
 */
final class Local
{
    public const UNAVAILABLE = 'GaiaDesk is not serving its local API here: is the app running, and is Settings → GaiaDesk API → Local API on?';

    /** The pipe-name form of a user name: lowercased, `[a-z0-9._-]` kept, the rest `_`, at most 64 characters, `user` if empty. */
    public static function pipeUser(string $name): string
    {
        $s = substr((string) preg_replace('/[^a-z0-9._-]/', '_', strtolower($name)), 0, 64);

        return '' !== $s ? $s : 'user';
    }

    /**
     * The local API's Windows pipe: `$GAIADESK_API_PIPE`, else `\\.\pipe\gaiadesk-api-<user>`.
     *
     * @param array<string, string> $env
     */
    public static function pipeName(array $env, string $username = ''): string
    {
        if ('' !== ($env['GAIADESK_API_PIPE'] ?? '')) {
            return $env['GAIADESK_API_PIPE'];
        }
        $user = '' !== ($env['USERNAME'] ?? '') ? $env['USERNAME'] : $username;

        return '\\\\.\\pipe\\gaiadesk-api-'.self::pipeUser($user);
    }

    private static function isAbsolute(string $p, bool $windows): bool
    {
        return $windows ? 1 === preg_match('#^(?:[a-zA-Z]:[\\\\/]|[\\\\/]{2})#', $p) : str_starts_with($p, '/');
    }

    private static function join(string $dir, string $name, bool $windows): string
    {
        return rtrim($dir, '\\/').($windows ? '\\' : '/').$name;
    }

    /**
     * The directory of the socket and the token: `$GAIADESK_API_DIR` when absolute, else `<home>/.gaiadesk`.
     *
     * @param array<string, string> $env
     */
    public static function apiDir(array $env, string $home, bool $windows): string
    {
        $d = $env['GAIADESK_API_DIR'] ?? '';
        if ('' !== $d && self::isAbsolute($d, $windows)) {
            return $d;
        }

        return self::join($home, '.gaiadesk', $windows);
    }

    /** @param array<string, string> $env */
    public static function socketPath(array $env, string $home, bool $windows): string
    {
        return self::join(self::apiDir($env, $home, $windows), 'api.sock', $windows);
    }

    /** @param array<string, string> $env */
    public static function tokenPath(array $env, string $home, bool $windows): string
    {
        return self::join(self::apiDir($env, $home, $windows), 'api-token', $windows);
    }

    /**
     * This process's environment.
     *
     * @return array<string, string>
     */
    public static function processEnv(): array
    {
        return getenv();
    }

    /**
     * The user's home directory.
     *
     * @param array<string, string> $env
     */
    public static function home(array $env, bool $windows): string
    {
        $h = $windows ? ($env['USERPROFILE'] ?? '') : ($env['HOME'] ?? '');
        if ('' === $h && \function_exists('posix_getpwuid') && \function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $h = \is_array($pw) ? $pw['dir'] : '';
        }
        if ('' === $h && $windows) {
            $h = ($env['HOMEDRIVE'] ?? '').($env['HOMEPATH'] ?? '');
        }

        return $h;
    }

    public static function unavailable(string $path, ?string $why = null): UnreachableException
    {
        return new UnreachableException(self::UNAVAILABLE.' ('.$path.(null !== $why ? ": $why" : '').')', ['kind' => 'unreachable', 'reason' => 'local_api_unavailable', 'exitCode' => 255]);
    }

    /**
     * The client that reaches the local API: curl over the Unix socket, or the named pipe opened as a file (Windows).
     */
    public static function client(string $where, bool $windows): TransportClient
    {
        if ($windows || str_starts_with($where, '\\\\')) {
            return new SocketClient(static function (RequestInterface $r, RequestOptions $o) use ($where) {
                $h = @fopen($where, 'r+b');
                if (false === $h) {
                    throw self::unavailable($where, error_get_last()['message'] ?? null);
                }

                return $h;
            }, 'localhost');
        }

        return new class($where) implements TransportClient {
            private CurlClient $curl;

            public function __construct(private readonly string $socket)
            {
                $this->curl = new CurlClient($socket);
            }

            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->sendWith($request, new RequestOptions());
            }

            public function sendWith(RequestInterface $request, RequestOptions $options): \Psr\Http\Message\ResponseInterface
            {
                if (!file_exists($this->socket)) {
                    throw Local::unavailable($this->socket);
                }
                try {
                    return $this->curl->sendWith($request, $options);
                } catch (NetworkException $e) {
                    if ($e->connectFailed) {
                        throw Local::unavailable($this->socket, $e->getMessage());
                    }
                    throw $e;
                }
            }
        };
    }

    /**
     * The local admin token, read from its file.
     *
     * @throws GaiaDeskException
     */
    public static function adminToken(string $file): string
    {
        if (!file_exists($file)) {
            throw new UnreachableException(self::UNAVAILABLE." (no local admin token at $file; or give an agent token as deskToken)", ['kind' => 'unreachable', 'reason' => 'local_api_unavailable', 'exitCode' => 255]);
        }
        $t = @file_get_contents($file);
        if (false === $t) {
            throw new GaiaDeskException("cannot read the local admin token $file", ['kind' => 'local', 'reason' => 'local']);
        }
        $t = trim($t);
        if ('' === $t) {
            throw new GaiaDeskException("the local admin token file $file is empty", ['kind' => 'local', 'reason' => 'local']);
        }

        return $t;
    }
}
