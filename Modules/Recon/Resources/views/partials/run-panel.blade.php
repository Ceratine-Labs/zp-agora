{{--
    A run's answer, as the recon centre's Auto tab shows it.

    The same result partial as the run page, so a proposal, a tick, a filter
    and an execute are the same things in both places. What is left out is the
    page around it — the arguments card and the extract drawer — which are one
    link away on the run page itself. The execute and reverse forms carry
    $centre, so they come back to this tab.

    Expects: $run (lines loaded), $area, $freshness, $centre.
--}}
<div class="run-panel-head">
    <p>
        <strong>Run #{{ $run->Id }}</strong>
        @if ($run->Note) — {{ $run->Note }} @endif
        · previewed {{ $run->CreatedAt?->diffForHumans() }}
        · {{ \App\Support\Format::n($run->TotalRows) }} {{ Str::plural('proposal', $run->TotalRows) }}
    </p>
    <span class="run-panel-actions">
        <a class="btn-ghost sm" href="{{ route('app.recon.run', $run) }}">Open the run page</a>
        @if ($run->Status === 'previewed')
            {{-- Again, over the same scope and the same readings, so the
                 answer to "what is left now" is one press. --}}
            <form method="POST" action="{{ route('app.recon.preview') }}" data-loader="Balancing {{ $area['label'] }}…">
                @csrf
                <input type="hidden" name="area" value="{{ $run->ReconArea }}">
                <input type="hidden" name="centre" value="1">
                <input type="hidden" name="branch_id" value="{{ $run->BranchId }}">
                <input type="hidden" name="from" value="{{ $run->FromDate->toDateString() }}">
                <input type="hidden" name="to" value="{{ $run->ToDate->toDateString() }}">
                @foreach ($run->params() as $name => $value)
                    @if (array_key_exists($name, config('recon.options')) && $value !== null)
                        <input type="hidden" name="options[{{ $name }}]" value="{{ $value }}">
                    @endif
                @endforeach
                <button type="submit" class="btn-ghost sm">Balance again</button>
            </form>
        @endif
    </span>
</div>

@include('recon::partials.result', ['run' => $run, 'area' => $area, 'stampMode' => $run->StampMode])
