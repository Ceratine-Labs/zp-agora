<x-app-shell :title="$setting->SettingKey">
    <x-page-head eyebrow="Setup · The business · System defaults and thresholds" :title="$setting->Description ?? $setting->SettingKey">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.settings.index') }}">Back to settings</a>
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif
    @include('core::setup.settings.detail')
</x-app-shell>
