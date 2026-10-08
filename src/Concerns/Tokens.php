<?php

declare(strict_types=1);

namespace GaiaDesk\Concerns;

use GaiaDesk\Exception\GaiaDeskException;
use GaiaDesk\GaiaDesk;
use GaiaDesk\Internal\Args;
use GaiaDesk\Transport\Call;
use GaiaDesk\Types;

/**
 * Scoped agent tokens on a desk. Token administration is the desk OWNER's: a signed-in
 * person's own account on their own desk (an agent token is refused, `agent_cannot_admin`).
 *
 * @internal part of {@see GaiaDesk}
 *
 * @phpstan-import-type MintResult from Types
 * @phpstan-import-type TokenInfo from Types
 * @phpstan-import-type Revoked from Types
 */
trait Tokens
{
    /**
     * `POST /desks/{id}/tokens`, once per desk: mint a scoped agent token on each; every
     * desk's token, its secret shown once. If a later desk fails, the exception's
     * `getJson()['tokens']` holds the tokens already minted.
     *
     * @param string|array<mixed> $desks   one desk id, or several (one token each, under one name)
     * @param string              $name    the token's name
     * @param int|string          $expires seconds, or `30m`, `24h`, `7d`, `2w` (default 7 days)
     * @param array<mixed>        $scopes  any of `exec`, `shell`, `cp`, `forward`, `jobs`, `screen`, `admin` (default exec, cp, jobs);
     *                                     `admin` lets it ASK to run as administrator: the desk owner's Admin access still decides
     * @param string|null         $cwd     confine its work to this directory on the desk
     * @param bool                $lowPriv run its work as the desk's low-privilege agent user, or refuse
     *
     * @return MintResult
     */
    public function createToken(
        string|array $desks,
        string $name,
        int|string $expires = '7d',
        array $scopes = ['exec', 'cp', 'jobs'],
        ?string $cwd = null,
        bool $lowPriv = false,
        ?string $deskToken = null,
        ?int $wake = null,
        ?string $idempotencyKey = null,
    ): array {
        $ids = array_map(static fn (string $d): string => Args::desk($d), \is_string($desks) ? [$desks] : Args::stringList($desks, 'desks'));
        if ([] === $ids) {
            throw Args::usage('at least one desk is required');
        }
        if ('' === trim($name)) {
            throw Args::usage('a token needs a name');
        }
        $scopes = Args::stringList($scopes, 'scopes');
        if ([] === $scopes || \in_array('', $scopes, true)) {
            throw Args::usage('scopes must be a non-empty list of scopes');
        }
        if (\in_array('admin', $scopes, true) && (null !== $cwd || $lowPriv)) {
            throw Args::usage('a token with the admin scope cannot be confined (cwd, lowPriv): a confined token never runs as administrator');
        }
        $spec = ['name' => $name, 'expires_secs' => Args::seconds($expires, 'expires'), 'scopes' => $scopes];
        if (null !== $cwd) {
            $spec['cwd'] = $cwd;
        }
        if ($lowPriv) {
            $spec['low_priv'] = true;
        }
        $key = self::idempotencyKey($idempotencyKey);
        $tokens = [];
        foreach ($ids as $i => $d) {
            try {
                $path = $this->deskPath($d).'/tokens';
                $r = $this->t->json(new Call('POST', $path, json: $spec, deskToken: $deskToken, wake: $wake, idempotencyKey: null !== $key && \count($ids) > 1 ? "$key:$i" : $key, e2e: ['desk' => $d, 'op' => 'token_mint', 'request' => ['op' => 'token_mint', 'spec' => $spec]], timeout: self::idleFor($wake)));
                foreach (self::listOf($r, 'tokens', "POST $path") as $t) {
                    $tokens[] = $t;
                }
            } catch (GaiaDeskException $e) {
                if ([] !== $tokens) {
                    $json = $e->getJson();
                    throw $e->with(['json' => (\is_array($json) ? $json : []) + ['tokens' => $tokens]]);
                }
                throw $e;
            }
        }

        /** @var MintResult */
        return ['tokens' => $tokens];
    }

    /**
     * `GET /desks/{id}/tokens`: the desk's agent tokens (never their secrets).
     *
     * @return list<TokenInfo>
     */
    public function listTokens(string $deskId, ?string $deskToken = null, ?int $wake = null): array
    {
        $path = $this->deskPath($deskId).'/tokens';

        /** @var list<TokenInfo> */
        return self::listOf($this->t->json(new Call('GET', $path, deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'token_list', 'request' => ['op' => 'token_list']], timeout: self::idleFor($wake))), 'tokens', "GET $path");
    }

    /**
     * `DELETE /desks/{id}/tokens/{token_id}`: revoke an agent token (by id or name); its
     * live sessions and jobs end.
     *
     * @return Revoked
     */
    public function revokeToken(string $deskId, string $tokenId, ?string $deskToken = null, ?int $wake = null): array
    {
        $tokenId = Args::tokenId($tokenId);
        $path = $this->deskPath($deskId).'/tokens/'.rawurlencode($tokenId);
        $r = self::object($this->t->json(new Call('DELETE', $path, deskToken: $deskToken, wake: $wake, e2e: ['desk' => Args::desk($deskId), 'op' => 'token_revoke', 'request' => ['op' => 'token_revoke', 'token' => $tokenId]], timeout: self::idleFor($wake))), "DELETE $path", 'a revocation');

        /** @var Revoked $r */
        return $r;
    }
}
