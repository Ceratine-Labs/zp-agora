@props(['report'])

<article class="report-menu-card" data-report-card="{{ $report['key'] }}">
    <a class="report-menu-link" href="{{ route('app.reports.show', $report['key']) }}">
        <x-report-art :variant="$report['key']" />
        <span class="report-menu-copy">
            <span class="report-menu-title">{{ $report['menu_label'] ?? $report['label'] }}</span>
            <span class="report-menu-summary">{{ $report['summary'] ?? Str::finish(Str::before($report['blurb'], '. '), '.') }}</span>
        </span>
    </a>
    <x-info class="report-menu-info" :label="'About '.$report['label']">
        <p>{{ $report['blurb'] }}</p>
        @if (! empty($report['was']))
            <p><strong>{{ __('reports::reports.was') }}:</strong> {{ implode(' · ', $report['was']) }}</p>
        @endif
        <p class="report-menu-procedure">agora.{{ $report['procedure'] }}</p>
    </x-info>
</article>
