<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-app single sign-on with Agora
    |--------------------------------------------------------------------------
    |
    | Agora and ZP-NQL run on one box (102.211.207.253) as sibling subdomains
    | of ceratine.com, sharing a brand and, increasingly, the same people. From
    | 2026-09-09 a session in either signs you into the other.
    |
    | OFF UNLESS CONFIGURED. `enabled` defaults to false and the middleware
    | additionally refuses to act without a secret and a peer URL, so a deploy
    | that ships this file without the env vars behaves exactly as before
    | rather than redirecting everyone to a host that is not there.
    |
    | The two apps must agree on `secret`. They must also disagree, exactly, on
    | `audience` / `peer.audience` — each app's audience is the other's
    | peer.audience, and that pairing is what stops a ticket minted for ZP
    | being replayed against Agora.
    |
    */

    'enabled' => (bool) env('SSO_ENABLED', false),

    /*
    | The signing key, shared with ZP-NQL and with nothing else. Generate with
    | `php -r "echo base64_encode(random_bytes(32));"` and put the SAME value
    | in both apps' .env files. Rotating it signs everybody out of the
    | handshake (not out of either app) until both sides carry the new value.
    */
    'secret' => env('SSO_SHARED_SECRET'),

    /*
    | What this app calls itself in a ticket, and what it will accept a ticket
    | FROM. Not free text in practice — the two values below must be mirrored
    | in Agora's config/sso.php.
    */
    'audience' => env('SSO_AUDIENCE', 'agora'),

    'peer' => [
        'name' => env('SSO_PEER_NAME', 'ZP-NQL'),
        // No trailing slash: every URL below is built by concatenation.
        /*
        | Also drives the "open ZP-NQL" link in the app bar, which is why it
        | has a real default: the two apps cross-link whether or not single
        | sign-on is switched on. Setting this alone does NOT enable sign-on —
        | that additionally needs SSO_ENABLED and a shared secret.
        */
        'url' => rtrim((string) env('SSO_PEER_URL', 'https://zp-db.ceratine.com'), '/'),
        'audience' => env('SSO_PEER_AUDIENCE', 'zp-nql'),
    ],

    /*
    | How long a minted ticket is good for, in seconds. This is a redirect
    | hop between two hosts in the same rack — 30 seconds is generous. Raising
    | it widens the window in which a ticket captured from a browser's history
    | or a proxy log is still worth something.
    */
    'ttl' => (int) env('SSO_TICKET_TTL', 30),

    /*
    | The loop guard.
    |
    | Without it, a signed-out visitor bounces forever: Agora asks ZP, ZP
    | says "nobody here", Agora renders its login page — and the next request
    | asks again. The cookie records "we already asked, and the answer was no".
    |
    | It is set on the PARENT domain on purpose, so one app's answer settles
    | the question for both; "neither of us has a session" is a fact about the
    | browser, not about the app that happened to ask.
    */
    'cookie' => [
        'name' => env('SSO_COOKIE_NAME', 'ceratine_sso_checked'),
        // e.g. `.ceratine.com`. Null keeps it host-only, which still stops the
        // loop for this app but makes the peer ask again on its own first hit.
        'domain' => env('SSO_COOKIE_DOMAIN'),
        'minutes' => (int) env('SSO_LOOPGUARD_MINUTES', 10),
    ],

];
