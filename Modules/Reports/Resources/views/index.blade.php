{{-- Compact report shortcuts; full descriptions and previous names stay
     available behind each card's information button. --}}
<x-app-shell :title="__('reports::reports.title')">
    <x-page-head eyebrow="Today" :title="__('reports::reports.title')">
        <x-slot:info>
            <p>{{ __('reports::reports.blurb') }}</p>
            <p>Use a report's information button for its full description, previous names and procedure.</p>
        </x-slot:info>
    </x-page-head>

    @foreach ($catalogue as $group => $reports)
        <x-card :title="$group" class="report-catalogue-group" flush>
            <div class="report-menu-grid">
                @foreach ($reports as $report)
                    <x-report-menu-card :report="$report" />
                @endforeach
            </div>
        </x-card>
    @endforeach
</x-app-shell>
