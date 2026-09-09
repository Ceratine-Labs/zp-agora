<?php

namespace Modules\Core\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The signed blobs the two apps hand each other.
 *
 * ZP-NQL and Agora sit on one box under one brand and, since 2026-09-09,
 * share a sign-in — but they share nothing else. Different databases
 * (Postgres and SQL Server), different user tables, different APP_KEYs. So a
 * shared session cookie was never available: this is a handshake, not a
 * shared store.
 *
 * A ticket is `base64url(json).base64url(hmac-sha256)`. Deliberately not a
 * JWT: a JWT carries its own algorithm in a header that an attacker gets to
 * choose, and the whole `alg: none` family of mistakes comes from honouring
 * it. Here the algorithm is not negotiable and there is no header to lie in.
 *
 * Three things make a stolen ticket close to worthless: a 30-second life, a
 * single-use `jti` burned in the cache on first redemption, and an `aud` that
 * names the app allowed to redeem it — so a ticket lifted out of a redirect to
 * Agora cannot be replayed against ZP.
 *
 * THE SUBJECT IS ALWAYS AN AGORA USER ID. Both directions. Agora resolves it
 * as its own primary key; ZP resolves it through users.agora_user_id. One
 * column, on one side, addresses both apps — which is why Agora needs no
 * schema change and no knowledge that ZP exists.
 *
 * The mirror of this class lives at app/Support/Sso/SsoTicket.php in the
 * ZP-NQL repo. They must stay byte-compatible; if you change the payload shape
 * or the signing base here, change it there in the same session.
 */
final class SsoTicket
{
    /** Claims a ticket carries. Anything else in the payload is ignored. */
    public const CLAIMS = ['sub', 'iss', 'aud', 'iat', 'exp', 'jti'];

    /**
     * Mint a ticket naming an Agora user id.
     *
     * @param  int  $agoraUserId  the subject — see the class note
     */
    public static function mint(int $agoraUserId, string $audience): string
    {
        $now = self::now();

        return self::sign([
            'sub' => $agoraUserId,
            'iss' => (string) config('sso.audience'),
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + max(5, (int) config('sso.ttl', 30)),
            'jti' => (string) Str::uuid(),
        ]);
    }

    /**
     * Verify a ticket and burn it, returning the Agora user id it named.
     *
     * Null for every failure — a bad signature, an expired ticket, one
     * addressed to the other app, one already redeemed. The caller must not
     * be able to tell those apart: which of them it was is only ever useful
     * to somebody probing the endpoint.
     */
    public static function redeem(?string $ticket): ?int
    {
        $claims = self::verify($ticket, (string) config('sso.audience'));

        if ($claims === null) {
            return null;
        }

        // Single use. `add` is atomic on every store this app runs on, so two
        // simultaneous redemptions of one ticket cannot both win the race.
        $burned = Cache::add(
            'sso:jti:'.$claims['jti'],
            true,
            now()->addSeconds(max(60, (int) config('sso.ttl', 30) * 4)),
        );

        return $burned ? (int) $claims['sub'] : null;
    }

    /**
     * Sign the query parameters of a request to the peer.
     *
     * The emit endpoint takes a `return` URL, and an unsigned one would let
     * anybody point this app's sign-in handshake at a host they control and
     * collect the ticket. Signing means the peer only ever redirects to a URL
     * this app actually asked for.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, scalar>  the same params plus `ts` and `sig`
     */
    public static function signParams(array $params): array
    {
        $params['ts'] = time();
        ksort($params);
        $params['sig'] = self::hmac(self::canonical($params));

        return $params;
    }

    /**
     * Check parameters signed by the peer, within the ticket's time window.
     *
     * @param  array<string, mixed>  $params
     */
    public static function checkParams(array $params): bool
    {
        $sig = $params['sig'] ?? null;
        unset($params['sig']);

        if (! is_string($sig) || ! isset($params['ts'])) {
            return false;
        }

        // A wider window than a ticket's: this only protects a redirect target
        // that was already ours, and a user on a slow link should not be told
        // to sign in twice because the round trip took 40 seconds.
        if (abs(self::now() - (int) $params['ts']) > max(60, (int) config('sso.ttl', 30) * 4)) {
            return false;
        }

        ksort($params);

        return hash_equals(self::hmac(self::canonical($params)), $sig);
    }

    // ------------------------------------------------------------- internals

    /**
     * The current unix time, from the framework's clock rather than PHP's.
     *
     * Both sides of this handshake are comparing wall-clock seconds, so the
     * value has to be the real one in production — Carbon::now() is, and it
     * costs nothing. What it buys is a TTL that can actually be tested:
     * raw time() ignores Laravel's travel() helper, so an expiry test written
     * against it silently passes a ticket that should have died.
     */
    private static function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** @param  array<string, mixed>  $claims */
    private static function sign(array $claims): string
    {
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $payload.'.'.self::b64(self::raw($payload));
    }

    /** @return array<string, mixed>|null */
    private static function verify(?string $ticket, string $audience): ?array
    {
        if (! is_string($ticket) || ! str_contains($ticket, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $ticket, 2);

        // Compared before the payload is even decoded: a signature check that
        // runs after parsing is a parser exposed to unauthenticated input.
        if (! hash_equals(self::b64(self::raw($payload)), $signature)) {
            return null;
        }

        $claims = json_decode((string) self::unb64($payload), true);

        if (! is_array($claims)) {
            return null;
        }

        foreach (self::CLAIMS as $claim) {
            if (! isset($claims[$claim])) {
                return null;
            }
        }

        if ((int) $claims['exp'] < self::now()) {
            return null;
        }

        // Addressed to us, and issued by the peer we are configured to trust.
        if (! hash_equals((string) $claims['aud'], $audience)) {
            return null;
        }

        if (! hash_equals((string) $claims['iss'], (string) config('sso.peer.audience'))) {
            return null;
        }

        return $claims;
    }

    /** @param  array<string, mixed>  $params */
    private static function canonical(array $params): string
    {
        return implode('&', array_map(
            fn ($k, $v) => $k.'='.rawurlencode((string) $v),
            array_keys($params),
            $params,
        ));
    }

    private static function hmac(string $data): string
    {
        return self::b64(self::raw($data));
    }

    private static function raw(string $data): string
    {
        $secret = (string) config('sso.secret');

        if ($secret === '') {
            // Loud rather than silently signing with an empty key, which would
            // make every peer with the same bug a trusted issuer.
            throw new \RuntimeException('SSO_SHARED_SECRET is not set — cross-app sign-in cannot sign anything.');
        }

        return hash_hmac('sha256', $data, $secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
