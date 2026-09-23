<x-app-shell title="Auto reconciliation">
    <x-page-head
        eyebrow="Banking and reconciliation"
        title="Auto reconciliation"
        blurb="Preview what a reconciliation would do, then execute it. The preview reads the customer's
               bank statement and deposit tables and changes nothing." />

    @if ($stampMode === 'journal')
        <x-notice tone="info" collapsible title="Preview only, deliberately" style="margin-bottom:16px">
            <p>Execute writes the reconciliation stamp into PumpIT, and Agora does not write to the
               customer's databases without an explicit decision on the record. Until that decision exists,
               a run is previewed, recorded and reviewable, and nothing in PumpIT moves.</p>
            <p>There is a second reason. The procedure Execute would be replacing — the live
               <code>sp_AUTOReconcile_*_BankRecon</code> family — carries a comparison defect that stamps
               bank lines against deposits that do not exist, and we recommended in writing on
               18 August 2026 that ZP stop using it. Reproducing that behaviour behind a nicer button
               would be the wrong thing to ship.</p>
        </x-notice>
    @endif

    @include('recon::partials.open-run')

    {{-- Not one of the five: a trace crosses all of them and both sides, so
         it sits above the areas rather than inside one. --}}
    <p class="field-help" style="margin-bottom:12px">
        Chasing a single figure? <a href="{{ route('app.recon.trace') }}">Trace a reference</a> — a batch
        number, a bag, a slip, a terminal or part of a bank narrative — and see every run, batch, stamp,
        statement line and deposit that touched it.
    </p>

    <x-card title="The five areas" sub="Each one previews through its own procedure on the Agora database">
        <div class="area-grid">
            @foreach ($areas as $key => $area)
                <a class="area-card" href="{{ route('app.recon.area', $key) }}">
                    <h3>{{ $area['label'] }}</h3>
                    <p>{{ $area['blurb'] }}</p>
                    <span class="area-proc">agora.{{ $area['procedure'] }}</span>
                </a>
            @endforeach
        </div>
    </x-card>

    {{--
        The same grid the area's Runs tab renders, with no area argument.

        It replaces a hand-written ten-row table that could not be filtered,
        sorted, extracted or scoped to a person — which made it the only list
        in Agora you could not get out of the screen. One definition rather
        than a second list, so the column widths a person sets on the tab are
        the widths they get here.
    --}}
    @php($mine = \Modules\Recon\Grids\ReconRunGrid::wantsOwnRunsOnly())
    @php($openOnly = \Modules\Recon\Grids\ReconRunGrid::wantsOpenOnly())
    <x-card title="Runs"
            :sub="($openOnly ? 'Open work' : 'Every run').' across the five areas, '.($mine ? 'yours' : 'everyone\'s').', newest first'"
            flush>
        <x-slot:actions>
            <x-tabs label="Which runs"
                    :active="$openOnly ? 'open' : 'all'"
                    :items="[
                        ['key' => 'open', 'label' => 'Open work', 'href' => route('app.recon.index', array_filter(['scope' => $mine ? null : 'all']))],
                        ['key' => 'all', 'label' => 'Everything', 'href' => route('app.recon.index', array_filter(['scope' => $mine ? null : 'all', 'show' => 'all']))],
                    ]" />
            {{-- Scoped like the list beside it: this site, and only your own
                 runs while the list shows only yours. It used to take
                 everybody's. A sweep never takes a run marked complete. --}}
            @if ($context->id() !== null)
                <form method="POST" action="{{ route('app.recon.clear') }}"
                      data-confirm="Discard {{ $mine ? 'your' : 'every' }} preview{{ $mine ? 's' : '' }} for this site?"
                      data-confirm-text="Across all five areas. Nothing in PumpIT is affected — a preview is a record of a read. Any run that has been executed is kept, and so is any run marked complete."
                      data-confirm-action="Discard previews" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    @if ($mine)<input type="hidden" name="mine" value="1">@endif
                    <button type="submit" class="btn-ghost">Clear previews</button>
                </form>
            @endif
        </x-slot:actions>

        <x-data-grid :grid="$grid" :branches="$branches" />
    </x-card>
</x-app-shell>
