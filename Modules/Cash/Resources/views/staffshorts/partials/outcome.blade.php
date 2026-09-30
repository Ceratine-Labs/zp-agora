{{--
    What the last press said — as a toast, and as a notice that stays.

    A refusal carries its AGORA code. The toast is SweetAlert2 through
    window.Agora.notify, the one every screen uses; it fades, so the same words
    stay on the page as a notice for anybody who looked away. The drop safe's
    outcome partial is the same shape.

    Props: none — reads the session's staffShortRefusal / staffShortStatus /
           staffShortCode, and $errors.
--}}
@php
    $refused = session('staffShortRefusal');
    $status = session('staffShortStatus');
    $code = session('staffShortCode');
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
    <div hidden data-staffshort-toast
         data-toast-icon="{{ $refused ? 'error' : 'success' }}"
         data-toast-text="{{ $refused ? trim(($code ? $code.' · ' : '').$refused) : $status }}"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var hook = document.querySelector('[data-staffshort-toast]');
            if (hook && window.Agora && window.Agora.notify) {
                window.Agora.notify.toast(hook.dataset.toastText, hook.dataset.toastIcon);
            }
        });
    </script>
@endif
