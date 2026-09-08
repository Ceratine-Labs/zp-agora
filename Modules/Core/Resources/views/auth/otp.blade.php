{{--
    The code from the mail — step two of three.

    Why this page exists at all: the link on its own is a bearer credential,
    and Agora's link is the FRONT DOOR for 88 people migrated out of SS_Users
    with no usable password. A forwarded mail or a browser history is one click
    from an ERP account. The link says WHICH request; the code says WHO.

    It is not two-factor and this page does not pretend it is — both secrets
    travel in the same message. What it buys is that a URL alone is not enough.

    The address is carried hidden and shown read-only, exactly as on the
    password step: the token is valid for one address, and letting somebody
    type a different one turns a wrong keystroke into "no longer valid" with no
    explanation.
--}}
<x-core::signin-layout title="Enter your code">
    <x-slot:hero>
        <x-core::signin-hero />
    </x-slot:hero>

    <h1>{{ __('core::core.password.otp_heading') }}</h1>

    @if ($closed)
        {{-- Said BEFORE a code is typed. Making somebody enter one only to be
             told the link expired an hour ago is a support call with extra
             steps. --}}
        <x-notice tone="warn" title="This link will not work">{{ $closed }}</x-notice>

        <p class="signin-links">
            <a href="{{ route('password.request') }}">{{ __('core::core.password.otp_expired') }}</a>
        </p>
    @else
        <p class="signin-blurb">
            {{ __('core::core.password.otp_blurb', ['length' => \Modules\Core\Models\PasswordReset::OTP_LENGTH]) }}
        </p>

        @if ($errors->any())
            <div class="signin-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.verify') }}" class="signin-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">

            <label>
                <span>{{ __('core::core.signin.email') }}</span>
                <input type="email" value="{{ $email }}" readonly autocomplete="username">
            </label>

            <label>
                <span>{{ __('core::core.password.otp_label') }}</span>
                {{-- inputmode/autocapitalize/spellcheck: this is typed on a
                     phone, and the defaults there are a lower-case keyboard
                     and a red squiggle under every code. `one-time-code` lets
                     the OS offer it straight out of the mail. --}}
                {{-- maxlength is LENGTH + 4, not LENGTH. The server strips
                     whitespace and folds case before it compares, but a
                     browser truncates on the way in — so a code pasted out of
                     a mail client with the trailing space it selected would
                     lose its last character and burn an attempt on a code the
                     person copied correctly. --}}
                <input type="text" name="otp" class="otp-input"
                       maxlength="{{ \Modules\Core\Models\PasswordReset::OTP_LENGTH + 4 }}"
                       required autofocus
                       autocomplete="one-time-code" inputmode="text"
                       autocapitalize="characters" autocorrect="off" spellcheck="false">
            </label>

            <button type="submit" class="btn-primary">{{ __('core::core.password.otp_submit') }}</button>
        </form>

        @if ($remaining !== null && $remaining < \Modules\Core\Models\PasswordReset::MAX_ATTEMPTS)
            <p class="signin-blurb">
                {{ $remaining }} {{ \Illuminate\Support\Str::plural('attempt', $remaining) }} left before this link locks.
            </p>
        @endif
    @endif

    <p class="signin-links">
        <a href="{{ route('login') }}">{{ __('core::core.password.back') }}</a>
    </p>
</x-core::signin-layout>
