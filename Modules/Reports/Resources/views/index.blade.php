<x-app-shell :title="__('reports::reports.title')">
    <x-page-head
        eyebrow="Today"
        :title="__('reports::reports.title')"
        :blurb="__('reports::reports.blurb')" />

    <x-notice tone="info" collapsible title="Where these numbers come from" style="margin-bottom:16px">
        <p>Every report on this page reads the customer's own databases — PumpIT for the ERP,
           and the POS landing zone alongside it — through read-only views. Nothing is copied into
           Agora and nothing is written back: the reports are fifteen stored procedures on the Agora
           database that reach across to the estate and return rows.</p>
        <p>Each grid names the procedure that produced it. That name is the thing to open in SSMS
           when a definition needs changing — it is meant to be readable and it is meant to be edited.</p>
    </x-notice>

    @foreach ($catalogue as $group => $reports)
        <x-card :title="$group" flush>
            <div class="area-grid">
                @foreach ($reports as $report)
                    <a class="area-card" href="{{ route('app.reports.show', $report['key']) }}">
                        <h3>{{ $report['label'] }}</h3>
                        <p>{{ $report['blurb'] }}</p>

                        @if (! empty($report['caveat']))
                            <p class="report-caveat">{{ $report['caveat'] }}</p>
                        @endif

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
