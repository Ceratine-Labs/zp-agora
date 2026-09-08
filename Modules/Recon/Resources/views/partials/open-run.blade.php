{{--
    Where the clerk left off.

    A run that was previewed and never committed is unfinished work, and until
    now the only way back to it was to remember its number or scroll a list of
    ten. `agora.ReconRun` has recorded who ran every preview since the module
    landed; this is the first thing to read that column.

    Renders nothing at all when there is no open run — an empty "you have no
    unfinished work" panel on every visit is noise, not reassurance.

    Expects: $open (ReconRun|null), and optionally $scope ('area'|'all').
--}}
@if (!empty($open))
    <x-notice tone="info" title="You have a run open" style="margin-bottom:16px">
        <p>
            <strong>{{ $open->Note ?: $open->FromDate?->toDateString().' to '.$open->ToDate?->toDateString() }}</strong>
            — {{ config("recon.areas.{$open->ReconArea}.label", $open->ReconArea) }},
            site {{ $open->BranchId }},
            {{ \App\Support\Format::n($open->TotalRows) }} {{ Str::plural('proposal', $open->TotalRows) }},
            {{ \App\Support\Format::n($open->MatchedRows) }} would reconcile
            ({{ \App\Support\Format::r($open->MatchedTotal) }}).
            Previewed {{ $open->CreatedAt?->diffForHumans() }} and never committed.
        </p>
        <p><a class="btn-primary" href="{{ route('app.recon.run', $open) }}">Resume run #{{ $open->Id }}</a></p>
    </x-notice>
@endif
