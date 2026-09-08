{{--
    The set-your-password mail body.

    Plain, deliberately. This message is read on a forecourt on a phone, and it
    has one job: get one link and one code in front of one person. T019 replaces
    this file with an editable agora.EmailTemplate under the code
    `core.password_reset` — the variables it uses here are the ones that
    template's registry has to declare.

    THE CODE IS SPELT OUT IN THE TEXT, not only in a styled block. Mail clients
    strip CSS, and a code somebody cannot find is a code they cannot type.
--}}
<p>Hello {{ $name }},</p>

<p>Somebody asked to set the password on your Agora account. If that was you,
   follow this link and enter the code below.</p>

<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Your code is <strong style="font-size:20px;letter-spacing:4px;">{{ $otp }}</strong></p>

<p style="font-size:20px;letter-spacing:6px;font-family:monospace;margin:14px 0;">{{ $otp }}</p>

<p>The link and the code both expire in {{ $minutes }} minutes, and the link
   works once. After {{ $attempts }} incorrect codes it stops working and you
   will need to ask for a new one.</p>

<p>If it was not you, nothing has happened to your account. Do not follow the
   link, and the code on its own is no use to anybody.</p>

<p>Agora — Zululand Retail &amp; Petroleum</p>
