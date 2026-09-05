{{--
    The forgot-password mail body.

    Plain, deliberately. This message is read on a forecourt on a phone, and it
    has one job: get one link in front of one person. T019 replaces this file
    with an editable agora.EmailTemplate under the code
    `core.password_reset` — the variables it uses here are the ones that
    template's registry has to declare.
--}}
<p>Hello {{ $name }},</p>

<p>Somebody asked to set the password on your Agora account. If that was you,
   follow this link:</p>

<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>The link works once and expires in {{ $minutes }} minutes.</p>

<p>If it was not you, nothing has happened to your account and you can ignore
   this message.</p>

<p>Agora — Zululand Retail &amp; Petroleum</p>
