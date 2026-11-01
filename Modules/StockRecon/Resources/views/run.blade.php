{{--
    The run loads every permitted panel once. The shared tabs component switches
    the panels locally and remembers the active panel in the tab query string.
    The existing app loader covers the initial table setup on every entry route.
--}}
{{-- Every run tab is wide. The proposals table carries
     fifteen columns — one more than the exceptions grid — and was the one
     left on the reading measure; see `table.dt.fit` for what that cost. --}}
@php(request()->attributes->set('loader_hold', 'Preparing the run…'))
<x-app-shell :title="'Run #'.$run->Id" wide>
    <x-page-head
        eyebrow="Stock recon centre"
        :title="'Run #'.$run->Id.' — '.($branch?->Name ?? 'site '.$run->BranchId)"
        :blurb="$run->FromDate->format('j M Y').' to '.$run->ToDate->format('j M Y')
                .' · '.$run->days().' '.Str::plural('day', $run->days())
                .' · '.($run->AreaNo === null ? 'every counting area' : 'one counting area')">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.helper', $run) }}">Explain this run</a>
            <a class="btn-ghost" href="{{ route('app.stockrecon.tutorial') }}">Tutorial</a>
            <a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Run another</a>
            @unless ($run->isCommitted())
                <form method="POST" action="{{ route('app.stockrecon.discard', $run) }}" data-loader="Discarding…"
                      data-confirm="Discard run #{{ $run->Id }}?"
                      data-confirm-text="This preview and its {{ $run->TotalRows }} {{ Str::plural('shift', $run->TotalRows) }} go. Nothing in PumpIT is affected — a preview is a record of a read."
                      data-confirm-action="Discard" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost">Discard</button>
                </form>
            @endunless
        </x-slot:actions>
    </x-page-head>

    @if (session('committed'))
        {{-- What actually happened, INCLUDING what was skipped and why. The
             legacy procedure reports nothing at all, so a row it declined to
             touch is indistinguishable from one it never considered. --}}
        @php($lines = collect(session('committedLines', [])))
        @php($skipped = $lines->where('Result', 'skipped'))
        <x-notice :tone="session('committedCode') === 'COMMITTED' ? 'info' : 'warn'"
                  :title="session('committed')" style="margin-bottom:16px">
            @if ($skipped->isEmpty())
                <p>Every ticked shift went through.</p>
            @else
                <p>{{ $skipped->count() }} {{ Str::plural('shift', $skipped->count()) }} skipped rather
                   than written — each says why:</p>
                <ul>
                    @foreach ($skipped->take(12) as $row)
                        <li><code>{{ $row['StockItemNo'] ?? '—' }}</code>
                            {{ $row['TransactionDate'] ?? '' }} shift {{ \Modules\StockRecon\Support\ShiftLabel::show($row['ShiftNo'] ?? null) }}
                            — {{ $row['SkipReason'] }}</li>
                    @endforeach
                    @if ($skipped->count() > 12)<li>… and {{ $skipped->count() - 12 }} more.</li>@endif
                </ul>
            @endif
        </x-notice>
    @endif

    @if (session('refusal'))
        <x-notice tone="stop" title="That could not be done" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    @include('stockrecon::partials.released-notice')

    <x-tabs :items="$tabs" :active="$tab" query="tab" label="Run" style="margin:16px 0">
        @foreach ($panes as $key => $pane)
            <x-tab-panel :key="$key">
                @include('stockrecon::panes.'.$key, $pane)
            </x-tab-panel>
        @endforeach
    </x-tabs>
{{-- What every code means, opened from the "i" on the exceptions and from
     any code badge on this page (7 Oct 2026). --}}
@include('stockrecon::partials.codes-modal')
</x-app-shell>
