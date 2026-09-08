{{--
    Where the clerk left off.

    A run that was previewed and never committed is unfinished work, and without
    this the only way back to it is to remember its number. Renders nothing at
    all when there is no open run — an empty "you have no unfinished work" panel
    on every visit is noise, not reassurance.

    Expects: $open (StockReconRun|null).
--}}
@if (! empty($open))
    <x-notice tone="info" title="You have a run open" style="margin-bottom:16px">
        <p>
            <strong>{{ $open->Note ?: $open->FromDate?->toDateString().' to '.$open->ToDate?->toDateString() }}</strong>
            — site {{ $open->BranchId }},
            {{ \App\Support\Format::n($open->TotalRows) }} {{ Str::plural('shift', $open->TotalRows) }},
            {{ \App\Support\Format::n($open->AmendedRows) }} to amend,
            {{ \App\Support\Format::r($open->NetOverValue) }} of unrecorded issue it cannot touch.
            Previewed {{ $open->CreatedAt?->diffForHumans() }} and never committed.
        </p>
        <p><a class="btn-primary" href="{{ route('app.stockrecon.run', $open) }}">Resume run #{{ $open->Id }}</a></p>
    </x-notice>
@endif
