{{--
    Choose a password, from the link in the mail.

    The address is carried in a hidden field and shown read-only: the token is
    only valid for one address, and letting somebody type a different one turns
    a wrong keystroke into "that link is no longer valid" with no explanation.
--}}
<x-core::signin-layout title="Choose a password">
    <x-slot:hero>
        <x-core::signin-hero />
    </x-slot:hero>

    <h1>{{ __('core::core.password.reset_heading') }}</h1>
    <p class="signin-blurb">{{ \Modules\Core\Support\PasswordPolicy::help() }}</p>

    @if ($errors->any())
        <div class="signin-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="signin-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>
            <span>{{ __('core::core.signin.email') }}</span>
            <input type="email" name="email" value="{{ old('email', $email) }}" readonly autocomplete="username">
        </label>
        <label>
            <span>{{ __('core::core.password.new') }}</span>
            <input type="password" name="password" required autofocus autocomplete="new-password">
        </label>
        <label>
            <span>{{ __('core::core.password.confirm') }}</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button type="submit" class="btn-primary">{{ __('core::core.password.save') }}</button>
    </form>

    <p class="signin-links">
        <a href="{{ route('login') }}">{{ __('core::core.password.back') }}</a>
    </p>
</x-core::signin-layout>
