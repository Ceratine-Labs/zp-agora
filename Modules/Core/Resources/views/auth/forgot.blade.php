{{--
    Ask for a reset link.

    Same frame as sign-in, and the hero has no system state on it: this page is
    reached by somebody who cannot get in, and four figures they cannot act on
    are noise at that moment.
--}}
<x-core::signin-layout title="Set your password">
    <x-slot:hero>
        <x-core::signin-hero />
    </x-slot:hero>

    <h1>{{ __('core::core.password.forgot_heading') }}</h1>
    <p class="signin-blurb">{{ __('core::core.password.forgot_blurb') }}</p>

    @if (session('status'))
        <x-notice tone="info">{{ session('status') }}</x-notice>
    @endif

    @if ($errors->any())
        <div class="signin-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="signin-form">
        @csrf
        <label>
            <span>{{ __('core::core.signin.email') }}</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <button type="submit" class="btn-primary">{{ __('core::core.password.send') }}</button>
    </form>

    <p class="signin-links">
        <a href="{{ route('login') }}">{{ __('core::core.password.back') }}</a>
    </p>
</x-core::signin-layout>
