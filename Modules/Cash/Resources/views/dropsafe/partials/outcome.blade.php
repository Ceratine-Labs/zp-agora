{{--
    What the last press said — as a toast, and as a notice that stays.

    A refusal carries its AGORA code (T039's acceptance: "refused with the AGORA
    code shown as a toast"). The toast is SweetAlert2 through
    window.Agora.notify, the one every screen uses; it fades, so the same words
    stay on the page as a notice for anybody who looked away.

    The hook is a hidden element read after load: window.Agora exists only once
    the application bundle has run, and module scripts run before
    DOMContentLoaded, so the script below waits for that and no longer.

    Props: none — reads the session's dropSafeRefusal / dropSafeStatus /
           dropSafeCode, and $errors.
--}}
@php
    $refused = session('dropSafeRefusal');
    $status = session('dropSafeStatus');
    $code = session('dropSafeCode');
@endphp

@if ($refused)
    <x-notice tone="stop" title="That was not saved" style="margin-bottom:16px">
        <p>@if ($code)<code>{{ $code }}</code> · @endif{{ $refused }}</p>
    </x-notice>
@elseif ($status)
    <x-notice tone="info" style="margin-bottom:16px">
        <p>{{ $status }}</p>
    </x-notice>
@endif

@if ($errors->any())
    <x-notice tone="stop" title="That was not saved" style="margin-bottom:16px">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </x-notice>
@endif

@if ($refused || $status)
    <div hidden data-dropsafe-toast
         data-toast-code="{{ $code }}"
         data-toast-icon="{{ $refused ? 'error' : 'success' }}"
         data-toast-text="{{ $refused ? trim(($code ? $code.' · ' : '').$refused) : $status }}"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var hook = document.querySelector('[data-dropsafe-toast]');
            if (hook && window.Agora && window.Agora.notify) {
                window.Agora.notify.toast(hook.dataset.toastText, hook.dataset.toastIcon);
            }
        });
    </script>
@endif
