@if (session('archiveOutcome') !== null)
    @php($outcome = session('archiveOutcome'))
    <x-notice tone="info" :title="\App\Support\Format::n($outcome['archived']).' '.Str::plural('run', $outcome['archived']).' archived'" class="recon-archive-notice">
        <p>The runs and their proposals stay on record under Everything. Nothing in PumpIT changed.</p>
        @if ($outcome['skipped'] > 0)
            <p>{{ \App\Support\Format::n($outcome['skipped']) }} {{ Str::plural('run', $outcome['skipped']) }} skipped after rechecking eligibility and access.</p>
        @endif
    </x-notice>
@endif
@if ($errors->any())
    <x-notice tone="warn" title="The archive was not sent" class="recon-archive-notice">
        <ul>
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </x-notice>
@endif
