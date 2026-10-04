<x-app-shell title="Auto reconciliation">
    {{-- The standing explanation sits behind the info button beside the title
         (4 Oct 2026: the users skipped every paragraph on these screens). The
         trace used to be a sentence with a link in it; it is a button now. --}}
    <x-page-head eyebrow="Banking and reconciliation" title="Auto reconciliation">
        <x-slot:info>
            <p>Preview what a reconciliation would do, then execute it. The preview reads the bank statement and
               deposit tables in PumpIT and changes nothing.</p>
            <p><strong>Trace a reference</strong> follows one batch number, bag, slip, terminal or piece of bank
               narrative through every run, batch, stamp, statement line and deposit that touched it.</p>
            @if ($stampMode === 'journal')
                <p><strong>Journal mode.</strong> Execute records the run in Agora and nothing in PumpIT moves:
                   writing the stamp to the customer's database needs a decision on the record first.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.trace') }}">Trace a reference</a>
        </x-slot:actions>
    </x-page-head>

    @include('recon::partials.open-run')

    {{-- Each tile carries the first sentence of its area's blurb — what it
         pairs against what. The rest of the blurb is behind the info button on
         the area's own page. --}}
    <x-card title="The five areas">
        <div class="area-grid">
            @foreach ($areas as $key => $area)
                <a class="area-card" href="{{ route('app.recon.area', $key) }}">
                    <h3>{{ $area['label'] }}</h3>
                    <p>{{ Str::finish(Str::before($area['blurb'], '. '), '.') }}</p>
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
