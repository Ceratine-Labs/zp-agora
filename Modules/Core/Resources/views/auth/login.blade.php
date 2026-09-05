{{--
    Sign in.

    The mockup's shape: the hero on the left carries the name, what it stands
    for and the live system state; the panel on the right does one thing.
--}}
<x-core::signin-layout title="Sign in">
    <x-slot:hero>
        <x-core::signin-hero :state="$state" />
    </x-slot:hero>

    <h1>{{ __('core::core.signin.heading') }}</h1>
    <p class="signin-blurb">{{ __('core::core.signin.blurb') }}</p>

    @if (session('status'))
        <x-notice tone="info">{{ session('status') }}</x-notice>
    @endif

    @if ($errors->any())
        {{-- .signin-error rather than <x-notice>: the sign-in styles already
             carry it, and login.spec.js asserts against that class. --}}
        <div class="signin-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="signin-form">
        @csrf
        <label>
            <span>{{ __('core::core.signin.email') }}</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label>
            <span>{{ __('core::core.signin.password') }}</span>
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label class="signin-remember">
            <input type="checkbox" name="remember" value="1">
            <span>{{ __('core::core.signin.remember') }}</span>
        </label>
        <button type="submit" class="btn-primary">{{ __('core::core.signin.submit') }}</button>
    </form>

    <p class="signin-links">
        <a href="{{ route('password.request') }}">{{ __('core::core.signin.forgot') }}</a>
        <span class="db">{{ config('database.connections.'.config('agora.connections.app').'.database') }} · Agora v1.0</span>
    </p>
</x-core::signin-layout>
