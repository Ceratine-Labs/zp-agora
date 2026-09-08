{{--
    One run, two faces.

    The tabs are LINKS, not panels: each is its own result set with its own
    scope, so a tab is somewhere you can send someone — the rule everywhere else
    in Agora and the reason <x-tabs> has the mode at all. No JavaScript.

    The strip is built in the controller so it can drop a tab the person may not
    open, rather than offering a door that answers 403.
--}}
<x-app-shell :title="'Run #'.$run->Id" :wide="$tab === 'exceptions'">
    <x-page-head
        eyebrow="Stock recon centre"
        :title="'Run #'.$run->Id.' — '.($branch?->Name ?? 'site '.$run->BranchId)"
        :blurb="$run->FromDate->toDateString().' to '.$run->ToDate->toDateString()
                .' ('.$run->days().' '.Str::plural('day', $run->days()).'), '
                .($run->AreaNo === null ? 'every counting area' : 'one counting area')
                .'. The caps are stored with the run, so this answer stays reproducible.'">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Run another</a>
            @unless ($run->isCommitted())
                <form method="POST" action="{{ route('app.stockrecon.discard', $run) }}"
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
                <p>Every ticked shift went through. Each one was re-read first and its counts re-checked
                   against the source as it stands now, not as it stood at preview time.</p>
            @else
                <p>{{ $skipped->count() }} {{ Str::plural('shift', $skipped->count()) }} skipped rather
                   than written over — the counts have moved since the preview:</p>
                <ul>
                    @foreach ($skipped->take(12) as $row)
                        <li><code>{{ $row['StockItemNo'] ?? '—' }}</code>
                            {{ $row['TransactionDate'] ?? '' }} shift {{ $row['ShiftNo'] ?? '' }}
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

    {{-- Provenance, not the answer: it has to be on the page and it should not
         be the first thing between the reader and the run. --}}
    <x-card title="What this run was given"
            sub="The exact arguments, stored so these figures stay reproducible after the caps are changed"
            collapsible flush>
        <x-table dense :count="count($run->params()) + 4">
            <x-slot:head>
                <tr><th class="l">Argument</th><th class="l">Value</th></tr>
            </x-slot:head>
            <tr><td class="mono">Procedure</td><td class="mono">{{ $run->ProcedureName }}</td></tr>
            <tr><td class="mono">Replaces</td><td class="mono">dbo.sp_UpdateAUTOStockReconBalancing</td></tr>
            <tr><td class="mono">Stamp mode</td><td>{{ $run->StampMode }}</td></tr>
            <tr><td class="mono">Counting area</td><td>{{ $run->AreaNo === null ? 'every area' : $run->AreaNo }}</td></tr>
            @foreach ($run->params() as $name => $value)
                <tr><td class="mono">{{ '@'.$name }}</td><td class="mono">{{ $value }}</td></tr>
            @endforeach
        </x-table>
    </x-card>

    <x-tabs :items="$tabs" :active="$tab" label="Run" style="margin:16px 0" />

    @include('stockrecon::panes.'.$tab)
</x-app-shell>
