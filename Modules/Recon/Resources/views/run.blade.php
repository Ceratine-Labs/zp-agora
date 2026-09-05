<x-app-shell :title="'Run #'.$run->Id">
    <x-page-head
        eyebrow="Auto reconciliation"
        :title="$area['label'].' — run #'.$run->Id"
        blurb="A preview as it was recorded. The parameters are stored with it, so this answer can be
               reproduced even after the customer edits their extraction rules.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.area', $run->ReconArea) }}">Run another</a>
            @unless ($run->isReconciled())
                <form method="POST" action="{{ route('app.recon.discard', $run) }}"
                      data-confirm="Discard run #{{ $run->Id }}?"
                      data-confirm-text="This preview and its {{ $run->TotalRows }} {{ Str::plural('proposal', $run->TotalRows) }} go. Nothing in PumpIT is affected — a preview is a record of a read."
                      data-confirm-action="Discard" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost">Discard</button>
                </form>
            @endunless
        </x-slot:actions>
    </x-page-head>

    @if (session('executed'))
        {{-- What actually happened, including what was skipped and why. The
             executable reports a count and nothing else, so a row it declined
             to touch is indistinguishable from one it never considered. --}}
        <x-notice :tone="session('executedCode') === 'COMMITTED' ? 'info' : 'warn'"
                  :title="session('executed')" style="margin-bottom:16px">
            @php($lines = collect(session('executedLines', [])))
            @php($blocked = $lines->where('State', 'blocked'))
            @if ($blocked->isEmpty())
                <p>Every selected batch went through. Each one was re-read first and its two sides
                   re-checked against the estate as it stands now, not as it stood at preview time.</p>
            @else
                <p>{{ $blocked->count() }} {{ Str::plural('batch', $blocked->count()) }} skipped rather
                   than stamped over — the estate has moved since the preview:</p>
                <ul>
                    @foreach ($blocked as $row)
                        <li><code>{{ $row['KeyRef'] ?? '—' }}</code> — {{ $row['Reason'] }}</li>
                    @endforeach
                </ul>
            @endif
        </x-notice>
    @endif

    @if (session('refusal'))
        <x-notice tone="stop" title="That run cannot be discarded" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    {{-- Provenance, not the answer: it has to be on the page and it should not
         be the first thing between the reader and the run. --}}
    <x-card title="What this run was given"
            sub="The exact arguments, stored so these figures stay reproducible after the customer edits their extraction rules"
            collapsible flush>
        <x-table dense :count="count($run->params()) + 3">
            <x-slot:head>
                <tr><th>Argument</th><th>Value</th></tr>
            </x-slot:head>
            <tr><td class="mono">Procedure</td><td class="mono">{{ $run->ProcedureName }}</td></tr>
            <tr><td class="mono">Replaces</td><td class="mono">dbo.{{ $area['legacy'] }}</td></tr>
            <tr><td class="mono">Stamp mode</td><td>{{ $run->StampMode }}</td></tr>
            @foreach ($run->params() as $name => $value)
                <tr><td class="mono">{{ '@'.$name }}</td><td class="mono">{{ $value }}</td></tr>
            @endforeach
        </x-table>
    </x-card>

    @include('recon::partials.result', ['run' => $run, 'area' => $area, 'stampMode' => $run->StampMode])
</x-app-shell>
