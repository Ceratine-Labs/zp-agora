{{-- Where the numbers come from sits behind the info button beside the title
     (Ryan, 4 Oct 2026). Each card carries the first sentence of its report's
     blurb — what it lists — and the whole blurb is behind the info button on
     the report's own page, as on the recon hub. A caveat is on that page too,
     where it stays open. --}}
<x-app-shell :title="__('reports::reports.title')">
    <x-page-head eyebrow="Today" :title="__('reports::reports.title')">
        <x-slot:info>
            <p>{{ __('reports::reports.blurb') }}</p>
            <p>The reports read PumpIT and the POS landing zone through read-only views: nothing is copied into Agora
               and nothing is written back. The procedure named on each card and grid is the one to open in SSMS
               when a definition needs changing.</p>
        </x-slot:info>
    </x-page-head>

    @foreach ($catalogue as $group => $reports)
        <x-card :title="$group" flush>
            <div class="area-grid">
                @foreach ($reports as $report)
                    <a class="area-card" href="{{ route('app.reports.show', $report['key']) }}">
                        <h3>{{ $report['label'] }}</h3>
                        <p>{{ Str::finish(Str::before($report['blurb'], '. '), '.') }}</p>

                        @if (! empty($report['was']))
                            <p class="report-was">
                                {{ __('reports::reports.was') }}: {{ implode(' · ', $report['was']) }}
                            </p>
                        @endif

                        <span class="area-proc">agora.{{ $report['procedure'] }}</span>
                    </a>
                @endforeach
            </div>
        </x-card>
    @endforeach
</x-app-shell>
