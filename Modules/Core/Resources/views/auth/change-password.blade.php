{{--
    Change your own password, inside the shell.

    Inside the shell rather than on the sign-in frame because the person IS
    signed in — hiding the application from them while they do it would make a
    routine change feel like a lockout. When RequirePasswordChange has sent
    them here, the notice at the top says so; the menu above is still theirs,
    it just will not take them anywhere yet.

    The blurb is the password rule, needed every time, so it stays on the page;
    the reassurance and the why sit behind info buttons (Ryan, 4 Oct 2026).
--}}
<x-app-shell title="Change your password">
    <x-page-head
        eyebrow="Your account"
        :title="__('core::core.password.change_heading')"
        :blurb="\Modules\Core\Support\PasswordPolicy::help()" />

    @if ($forced)
        <x-notice tone="warn" title="Set your own password first">
            {{ __('core::core.password.change_forced') }}
        </x-notice>
    @endif

    <x-card title="New password">
        <x-slot:info>
            <p>Nobody at head office can see the password you choose.</p>
        </x-slot:info>

        @if ($errors->any())
            <div class="signin-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('app.password.change.update') }}">
            @csrf
            @method('PUT')

            <div class="field-row">
                <x-field name="current_password" :label="__('core::core.password.current')" type="password"
                         info="Being signed in is not proof of being you — an unlocked laptop is the usual way an account changes hands." />
                <x-field name="password" :label="__('core::core.password.new')" type="password" />
                <x-field name="password_confirmation" :label="__('core::core.password.confirm')" type="password" />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">{{ __('core::core.password.save') }}</button>
            </div>
        </form>
    </x-card>
</x-app-shell>
