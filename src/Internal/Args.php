<?php

declare(strict_types=1);

namespace GaiaDesk\Internal;

use GaiaDesk\Exception\UsageException;

/**
 * Argument checks, the same rules (and the same UsageExceptions) as gaiadesk-cli and
 * the other GaiaDesk SDKs apply. Pure: nothing here does I/O.
 *
 * @internal
 */
final class Args
{
    /** The shells `exec` takes. */
    public const SHELLS = ['default', 'none', 'sh', 'bash', 'zsh', 'cmd', 'pwsh', 'powershell'];
    /** The shells a job takes (a job is a command line: no `none`; none given is the desk's default). */
    public const JOB_SHELLS = ['sh', 'bash', 'zsh', 'cmd', 'pwsh', 'powershell'];

    private const UNITS = ['s' => 1, 'sec' => 1, 'secs' => 1, 'm' => 60, 'min' => 60, 'mins' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    public static function usage(string $message): UsageException
    {
        return new UsageException($message, ['kind' => 'usage']);
    }

    /** A network time limit: a positive number of seconds, or null for no limit. */
    public static function timeLimit(?float $seconds, string $what): ?float
    {
        if (null !== $seconds && (!is_finite($seconds) || $seconds <= 0)) {
            throw self::usage("$what must be a positive number of seconds, or null for no limit (not ".var_export($seconds, true).')');
        }

        return $seconds;
    }

    /** A retry delay: a number of seconds, zero or more. */
    public static function delay(float $seconds, string $what): float
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw self::usage("$what must be a number of seconds, 0 or more (not ".var_export($seconds, true).')');
        }

        return $seconds;
    }

    /** A desk id: one token, no whitespace, not a flag (the API itself answers 400 `bad_desk_id` for anything but nine digits). */
    public static function desk(mixed $deskId): string
    {
        if (!\is_string($deskId) || '' === trim($deskId)) {
            throw self::usage('a desk id is required');
        }
        $d = trim($deskId);
        if (1 === preg_match('/\s/', $d) || str_starts_with($d, '-')) {
            throw self::usage('not a desk id: '.Json::encode($deskId));
        }

        return $d;
    }

    /** A job name: letters, digits, `.`, `_`, `-`, not starting with `-` (the API: at most 64). */
    public static function jobName(mixed $name): string
    {
        if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]*$/D', $name)) {
            throw self::usage('a job name is letters, digits, . _ - (not starting with -): '.Json::encode($name));
        }

        return $name;
    }

    /**
     * A duration as whole seconds: a number is seconds (rounded up), a string `90`,
     * `30s`, `10m`, `1h30m`, `7d`, `2w`.
     */
    public static function seconds(int|float|string $v, string $what): int
    {
        if (\is_int($v) || \is_float($v)) {
            if (!is_finite((float) $v) || $v < 0) {
                throw self::usage("$what must be a number of seconds >= 0");
            }

            return (int) ceil($v);
        }
        if (1 !== preg_match('/^\s*\d+\s*[a-z]*(\s*\d+\s*[a-z]+)*\s*$/iD', $v)) {
            throw self::usage("$what: not a duration: ".Json::encode($v));
        }
        $d = trim($v);
        if (1 === preg_match('/^\d+$/D', $d)) {
            return (int) $d;
        }
        $total = 0;
        preg_match_all('/(\d+)\s*([a-z]+)/i', $d, $m, \PREG_SET_ORDER);
        foreach ($m as $part) {
            $unit = self::UNITS[strtolower($part[2])] ?? null;
            if (null === $unit) {
                throw self::usage("$what: unknown unit in ".Json::encode($v));
            }
            $total += (int) $part[1] * $unit;
        }

        return $total;
    }

    /** A directory on the desk (relative: from the desk user's home, or a confined token's folder). */
    public static function cwd(mixed $cwd): string
    {
        if (!\is_string($cwd) || '' === trim($cwd) || str_contains($cwd, "\0")) {
            throw self::usage('cwd is a directory on the desk: '.Json::encode($cwd));
        }

        return $cwd;
    }

    /**
     * Environment variables for the command on the desk, `[NAME => value]`. A name is
     * non-empty, without `=`, whitespace or NUL; a value a string without NUL. Errors name
     * the variable, never its value.
     *
     * @return array<string, string>
     */
    public static function env(mixed $env): array
    {
        if (!\is_array($env)) {
            throw self::usage('env is an array of variable names to values');
        }
        $out = [];
        foreach ($env as $k => $v) {
            $k = (string) $k;
            if ('' === $k || 1 === preg_match('/[=\s\0]/', $k)) {
                throw self::usage('env: '.Json::encode($k).' is not an environment variable name');
            }
            if (!\is_string($v)) {
                throw self::usage("env: the value of $k must be a string");
            }
            if (str_contains($v, "\0")) {
                throw self::usage("env: the value of $k contains a NUL byte");
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** A shell for exec, as sent: `powershell` is `pwsh`. */
    public static function shell(mixed $shell): string
    {
        if (!\is_string($shell) || !\in_array($shell, self::SHELLS, true)) {
            throw self::usage('shell is one of '.implode(', ', self::SHELLS));
        }

        return 'powershell' === $shell ? 'pwsh' : $shell;
    }

    /** A shell for a job, as sent: `powershell` is `pwsh`. */
    public static function jobShell(mixed $shell): string
    {
        if (!\is_string($shell) || !\in_array($shell, self::JOB_SHELLS, true)) {
            throw self::usage("a job's shell is one of ".implode(', ', self::JOB_SHELLS));
        }

        return 'powershell' === $shell ? 'pwsh' : $shell;
    }

    /**
     * A command: a string is ONE command line for the desk's shell, verbatim; a list is
     * separate arguments, which the desk quotes for its shell.
     *
     * @param string|array<mixed> $command
     *
     * @return list<string>
     */
    public static function command(string|array $command, string $what = 'exec'): array
    {
        $argv = \is_string($command) ? [$command] : self::stringList($command, "$what's arguments");
        if ([] === $argv || (1 === \count($argv) && '' === trim($argv[0]))) {
            throw self::usage("$what needs a command");
        }

        return $argv;
    }

    /**
     * A list of strings (a command's arguments, scopes, events), checked.
     *
     * @return list<string>
     */
    public static function stringList(mixed $v, string $what): array
    {
        if (!\is_array($v) || !array_is_list($v)) {
            throw self::usage("$what must be a list of strings");
        }
        $out = [];
        foreach ($v as $s) {
            if (!\is_string($s)) {
                throw self::usage("$what must be a list of strings");
            }
            $out[] = $s;
        }

        return $out;
    }

    /** A memory cap in megabytes: a number, or `512M`, `2G` (`2GB`). */
    public static function memMb(int|string $mem): int
    {
        if (\is_int($mem)) {
            return $mem;
        }
        if (1 !== preg_match('/^\s*(\d+)\s*([MG])?B?\s*$/iD', $mem, $m)) {
            throw self::usage('mem: not a size: '.Json::encode($mem));
        }

        return (int) $m[1] * ('G' === strtoupper($m[2] ?? '') ? 1024 : 1);
    }

    /** A non-empty remote path. */
    public static function remotePath(mixed $p): string
    {
        if (!\is_string($p) || '' === $p) {
            throw self::usage('a remote path is required');
        }

        return $p;
    }

    /** A token name or id to revoke. */
    public static function tokenId(mixed $t): string
    {
        if (!\is_string($t) || '' === $t || str_starts_with($t, '-')) {
            throw self::usage('a token name or id is required');
        }

        return $t;
    }

    /** The base name of a local or remote path (either separator). */
    public static function basename(string $p): string
    {
        $parts = array_values(array_filter(preg_split('#[\\\\/]+#', $p) ?: [], static fn (string $s): bool => '' !== $s));

        return [] === $parts ? '' : $parts[\count($parts) - 1];
    }

    /** An optional non-empty credential string (trimmed), or null. */
    public static function credential(mixed $v, string $what): ?string
    {
        if (null === $v) {
            return null;
        }
        if (!\is_string($v) || '' === trim($v)) {
            throw self::usage("$what must be a non-empty string");
        }

        return trim($v);
    }

    /** A whole number in a range. */
    public static function intIn(mixed $v, int $min, int $max, string $what): int
    {
        if (!\is_int($v) || $v < $min || $v > $max) {
            throw self::usage("$what is a whole number, $min to $max");
        }

        return $v;
    }
}
