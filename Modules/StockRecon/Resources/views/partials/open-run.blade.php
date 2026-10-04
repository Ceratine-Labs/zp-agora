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
            · {{ \App\Support\Format::n($open->AmendedRows) }} to amend
            · previewed {{ $open->CreatedAt?->diffForHumans() }}
            &nbsp;<a class="btn-primary" href="{{ route('app.stockrecon.run', $open) }}">Resume run #{{ $open->Id }}</a>
        </p>
    </x-notice>
@endif
