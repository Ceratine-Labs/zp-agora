<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The small decisions both the middleware and the controller have to make the
 * same way — whether cross-app sign-in is on at all, what the loop-guard
 * cookie looks like, and which redirect targets are safe.
 *
 * Kept out of both so neither can answer one of them differently. A guard
 * cookie written with one domain and cleared with another is not cleared.
 */
final class Sso
{
    /**
     * Whether the handshake can run at all.
     *
     * Configuration, not just a flag: an app with `SSO_ENABLED=true` and no
     * secret would throw on the first mint, and one with no peer URL would
     * redirect to `/sso/emit` on its own host. Both are deployment mistakes
     * that should behave like "off", not like an outage.
     */
    public static function enabled(): bool
    {
        return (bool) config('sso.enabled')
            && (string) config('sso.secret') !== ''
            && (string) config('sso.peer.url') !== '';
    }

    /** The peer's emit endpoint, with a signed return address and destination. */
    public static function emitUrl(string $next): string
    {
        $params = SsoTicket::signParams([
            'return' => route('sso.accept').'?next='.rawurlencode($next),
        ]);

        return config('sso.peer.url').'/sso/emit?'.http_build_query($params);
    }

    /** The peer's logout endpoint, with a signed return address. */
    public static function peerLogoutUrl(string $return): string
    {
        $params = SsoTicket::signParams(['return' => $return]);

        return config('sso.peer.url').'/sso/logout?'.http_build_query($params);
    }

    /**
     * The "we already asked, and nobody was home" cookie.
     *
     * Not encrypted and not signed, deliberately: it carries no identity and
     * grants nothing. The worst a forged one can do is suppress a silent
     * sign-in for the browser that forged it, which is indistinguishable from
     * not wanting one. It must also be readable by the PEER, which cannot
     * decrypt this app's cookies.
     */
    public static function guardCookie(): SymfonyCookie
    {
        return Cookie::make(
            name: (string) config('sso.cookie.name'),
            value: '1',
            minutes: (int) config('sso.cookie.minutes'),
            domain: config('sso.cookie.domain'),
            secure: request()->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        );
    }

    public static function forgetGuardCookie(): SymfonyCookie
    {
        return Cookie::forget(
            (string) config('sso.cookie.name'),
            '/',
            config('sso.cookie.domain'),
        );
    }

    public static function guarded(): bool
    {
        return request()->cookie((string) config('sso.cookie.name')) !== null;
    }

    /**
     * A redirect target that cannot leave this host.
     *
     * `next` rides through the peer and comes back, so by the time it is read
     * it is attacker-controlled input. Anything that is not a rooted path on
     * this app becomes the home page — and `//evil.example` is rejected too,
     * because a browser reads a protocol-relative URL as another origin.
     */
    public static function safeNext(mixed $next): string
    {
        if (! is_string($next) || $next === '') {
            return '/';
        }

        if (! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/';
        }

        return $next;
    }
}
