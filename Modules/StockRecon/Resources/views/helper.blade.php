<x-app-shell :title="'Recon helper · run #'.$run->Id" wide>
    <x-page-head eyebrow="Stock recon" :title="'Recon helper · run #'.$run->Id"
                 blurb="Choose a question and inspect the evidence saved with this run.">
        <x-slot:actions>
            <a class="btn-primary" href="{{ route('app.stockrecon.run', $run) }}">Back to proposals</a>
            <a class="btn-ghost" href="{{ route('app.stockrecon.tutorial', ['lesson' => $topics[$topic]['lesson']]) }}">Learn this topic</a>
        </x-slot:actions>
    </x-page-head>

    <x-notice tone="info" title="Saved evidence · read-only helper">
        <p>These quantities were captured for run #{{ $run->Id }}. They are not an up-to-the-minute reading of PumpIT.
            The helper explains recorded findings; it does not change stock, release holds or decide responsibility.</p>
    </x-notice>
    <x-card title="Which run am I reviewing?">
        <x-facts>
            <x-fact label="Site" :value="$run->BranchId" />
            <x-fact label="Period" :value="$run->FromDate->format('d M Y').' to '.$run->ToDate->format('d M Y')" />
            <x-fact label="Counting area" :value="$run->AreaNo ?? 'Every area'" />
            <x-fact label="Preview saved" :value="\App\Support\BusinessDate::show($run->CreatedAt)" />
            <x-fact label="Run status now" :value="$run->Status">The status can change after the snapshot was saved.</x-fact>
            <x-fact label="Recorded operation" :value="$run->CommitKind === 'reset' ? 'Reset to original' : ($run->CommittedAt ? 'Balancing amendment' : 'Preview only')" />
            <x-fact label="Saved stamp mode" :value="$run->StampMode" />
            <x-fact label="Complete through" :value="$run->CompleteTo?->format('d M Y') ?? 'Not recorded'" />
            <x-fact label="Shift lines held back" :value="\App\Support\Format::n($run->HeldBackRows)" />
        </x-facts>
        @if ($run->CommitKind === 'reset')
            <x-notice tone="warn" title="Reset to original · these proposals were not the reset writes">
                <p>The recorded operation is Reset to original{{ $run->Status === 'reversed' ? ', since reversed' : '' }}.
                    Committed or skipped line states refer to that reset. The proposed counts, issues and variances below still show the earlier balancing calculation.</p>
                <p>Use the reset’s Audit trail entries for exact before-and-after values. Open the fresh preview created afterwards to review balancing from the restored baseline.</p>
                <p><a href="{{ route('app.stockrecon.index', ['branch_id' => $run->BranchId, 'area_no' => $run->AreaNo ?? 0, 'from' => $run->FromDate->toDateString()]) }}">Find or prepare a fresh preview</a>
                    · <a href="{{ route('app.reports.show', ['report' => 'audit-trail', 'branches' => [$run->BranchId]]) }}">Open this site’s Audit trail</a> — choose the reset date and run #{{ $run->Id }}.</p>
            </x-notice>
        @endif
        @if ($run->HeldBackRows > 0)
            <x-notice tone="warn" title="The requested period was not complete">
                <p>The saved preview held back incomplete days. Confirm missing shifts, closing counts and POS posting with the site before relying on a later end date. A fresh preview is needed to read later corrections.</p>
            </x-notice>
        @endif
        @if ($run->FailureMessage)
            <x-notice tone="stop" title="Saved run failure"><p>{{ $run->FailureMessage }}</p></x-notice>
        @endif
    </x-card>

    <x-card title="What would you like to understand?">
        <x-tabs :active="$topic" label="Recon helper questions" :items="collect($topics)->map(fn ($entry, $key) => [
            'key' => $key, 'label' => $entry['label'],
            'href' => route('app.stockrecon.helper', ['run' => $run, 'topic' => $key, 'line' => $line?->Id]),
        ])->values()->all()" />
        <p>{{ $topics[$topic]['help'] }}</p>
        <details @if ($line === null) open @endif>
            <summary>Choose an item chain · {{ $choiceCount }} {{ Str::plural('chain', $choiceCount) }} for this question</summary>
            @forelse ($choices as $choice)
                <p><a href="{{ route('app.stockrecon.helper', ['run' => $run, 'topic' => $topic, 'line' => $choice->Id, 'page' => $choicePage]) }}">{{ $choice->itemLabel() }}</a>
                    <span class="muted">· {{ $choice->StockItemNo }} · {{ $choice->areaLabel() }}</span></p>
            @empty
                <p>No saved chains match this question. Choose another question or return to the run’s proposals.</p>
            @endforelse
            @if ($choicePages > 1)
                <nav aria-label="Item chain choices">
                    @if ($choicePage > 1)<a class="btn-ghost" href="{{ route('app.stockrecon.helper', ['run' => $run, 'topic' => $topic, 'line' => $line?->Id, 'page' => $choicePage - 1]) }}">Previous chains</a>@endif
                    <span>Page {{ $choicePage }} of {{ $choicePages }} · {{ $choices->count() }} shown</span>
                    @if ($choicePage < $choicePages)<a class="btn-ghost" href="{{ route('app.stockrecon.helper', ['run' => $run, 'topic' => $topic, 'line' => $line?->Id, 'page' => $choicePage + 1]) }}">Next chains</a>@endif
                </nav>
            @endif
        </details>
    </x-card>

    @if ($line !== null)
        <x-card :title="$line->itemLabel().' · '.$line->areaLabel()">
            <x-slot:actions>
                <a class="btn-ghost" href="{{ route('app.stockrecon.line', [$run, $line]) }}">Open full chain evidence</a>
                @can('stockrecon.exceptions.view')
                    <a class="btn-ghost" href="{{ route('app.stockrecon.exceptions', ['run' => $run, 'group' => 'all', 'q' => $line->StockItemNo]) }}">Review exceptions</a>
                @endcan
            </x-slot:actions>
            <p>Item {{ $line->StockItemNo }} · {{ $shifts->count() }} saved {{ Str::plural('shift', $shifts->count()) }}. The selected question filters the choices above; the selected chain’s full evidence remains visible.</p>
            @foreach ($explanations as $explanation)
                <x-case :title="$explanation['title']" :tone="$explanation['tone']" :terms="$explanation['terms']">
                    <x-slot:actions><a href="{{ route('app.stockrecon.tutorial', ['lesson' => $explanation['lesson']]) }}">Read the related lesson</a></x-slot:actions>
                </x-case>
            @endforeach
        </x-card>

        <x-card title="Original, live at preview and proposed counts" flush>
            <p>Original is the saved original count pair, where this run captured it. Live at preview is what PumpIT held when the preview was made. Proposed is the calculated balancing answer{{ $run->CommitKind === 'reset' ? ', not the reset writes' : ', whether committed or not' }}. A dash means a value was not recorded.</p>
            <x-table :count="$shifts->count()" fit>
                <x-slot:head><tr><th>Date / shift</th><th class="num">Original open</th><th class="num">Original close</th><th class="num">Live open at preview</th><th class="num">Live close at preview</th><th class="num">Proposed open</th><th class="num">Proposed close</th></tr></x-slot:head>
                @foreach ($shifts as $shift)
                    @php($saved = $savedLines->get($shift->Id))
                    <tr><td>{{ substr($shift->TransactionDate, 0, 10) }} · {{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}</td>
                        <td class="num">{{ \App\Support\Format::qty($saved?->QtyOpenOrig) }}</td><td class="num">{{ \App\Support\Format::qty($saved?->QtyCloseOrig) }}</td>
                        <td class="num">{{ \App\Support\Format::qty($shift->QtyOpenNow) }}</td><td class="num">{{ \App\Support\Format::qty($shift->QtyCloseNow) }}</td>
                        <td class="num">{{ \App\Support\Format::qty($shift->QtyOpenNew) }}</td><td class="num">{{ \App\Support\Format::qty($shift->QtyCloseNew) }}</td></tr>
                @endforeach
            </x-table>
        </x-card>

        <x-card title="Recorded result and the next evidence to check" flush>
            <p>The issue and POS basis are the quantities this run used, including its explicit issue-correction rules. Variances below come from the saved calculation. Quantities alone do not authorise a charge.</p>
            <x-table :count="$shifts->count()" fit>
                <x-slot:head><tr><th>Date / shift</th><th class="num">Basis issue</th><th class="num">Proposed issue</th><th class="num">POS</th><th class="num">Variance before</th><th class="num">Variance proposed</th><th>Saved finding / result</th></tr></x-slot:head>
                @foreach ($shifts as $shift)
                    @php($saved = $savedLines->get($shift->Id))
                    <tr><td>{{ substr($shift->TransactionDate, 0, 10) }} · {{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}</td>
                        <td class="num">{{ \App\Support\Format::qty($shift->QtyIssued) }}</td><td class="num">{{ \App\Support\Format::qty($shift->QtyIssuedAmended) }}</td>
                        <td class="num">{{ \App\Support\Format::qty($shift->QtyPOS) }}</td><td class="num">{{ \App\Support\Format::qty($shift->QtyVar) }}</td><td class="num">{{ \App\Support\Format::qty($shift->QtyVarNew) }}</td>
                        <td>{{ $shift->Outcome }}<br><span class="muted">{{ $run->CommitKind === 'reset' ? 'Reset commit state:' : 'Commit state:' }} {{ $saved?->CommitState ?? 'Not recorded' }}
                            @if ($shift->ExceptionCode) · {{ $shift->ExceptionCode }} @endif @if ($shift->AuditCode) · Audit {{ $shift->AuditCode }} @endif</span>
                            @if ($saved?->BlockReason)<br>{{ $saved->BlockReason }}@endif</td></tr>
                @endforeach
            </x-table>
        </x-card>
    @else
        <x-card title="Review the run before choosing an action">
            @foreach ($explanations as $explanation)
                <x-case :title="$explanation['title']" :tone="$explanation['tone']" :terms="$explanation['terms']" />
            @endforeach
            <p>Choose an item above for explanations tied to that chain’s saved findings and quantities.</p>
        </x-card>
    @endif
</x-app-shell>
