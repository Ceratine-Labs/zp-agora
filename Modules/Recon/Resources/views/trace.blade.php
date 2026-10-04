<x-app-shell title="Trace a reference">
    <x-page-head eyebrow="Banking and reconciliation" title="Trace">
        <x-slot:info>
            <p>One value — a batch number, a bank reference, a bag, a slip, a terminal or a run number — and
               everything that touched it.</p>
            <p>The answers follow a reconciliation in order: the runs, proposals, batches, rows touched, cashier
               split and stamps from Agora's ledger, then the bank statement and deposits as they stand now —
               the check on everything above them.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.index') }}">All areas</a>
        </x-slot:actions>
    </x-page-head>

    <x-card title="What are you looking for?">
        <x-slot:info>
            <p>Answered by <code>{{ $procedure }}</code>: eight questions of the same value, in one call. It reads
               Agora's ledger and the customer's statement and deposit tables, and writes to neither.</p>
        </x-slot:info>
        <form method="GET" action="{{ route('app.recon.trace') }}">
            <div class="field-row">
                <x-field name="q" label="Reference or number" :value="$term"
                         help="At least three characters."
                         info="A batch, a bag, a slip, a terminal, a device, part of a bank narrative, a cashier, or a run number." />

                <x-field name="from" label="From" type="date" :value="$from"
                         info="The window is required: the statement table is large and live. Ninety days by default." />

                <x-field name="to" label="To" type="date" :value="$to" />

                @if ($branches)
                    <x-field name="branch_id" label="Site"
                             :choices="['' => 'Every site you may see'] + $branches->pluck('Name', 'BranchId')->all()"
                             :value="$branchId ?: ''"
                             info="Leave it on every site when you do not yet know where the reference lives." />
                @endif
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Trace</button>
                <span class="field-help">Read-only. Nothing is written anywhere.</span>
            </div>
        </form>
    </x-card>

    @if ($refusal)
        <x-notice tone="stop" title="That is not enough to go on" style="margin-bottom:16px">
            <p>{{ $refusal }}</p>
        </x-notice>
    @endif

    @if ($sets !== null)
        <x-card :title="'“'.$term.'”'"
                :sub="$from.' to '.$to.' — what each part of the system knows about it'"
                flush>
            {{-- The shape of the answer before the detail of it. "Nothing in
                 the ledger, three rows on the statement" is a complete answer
                 and a common one: it is what an unreconciled line looks like. --}}
            <x-statstrip :stats="collect($labels)->map(fn ($label, $key) => [
                'label' => $label,
                'value' => \App\Support\Format::n($sets[$key]->count()),
                'note' => $sets[$key]->isEmpty() ? 'nothing found' : 'found',
                'tone' => $sets[$key]->isEmpty() ? 'neutral' : 'good',
            ])->values()->all()" />
        </x-card>

        @include('recon::partials.trace-sets')
    @endif
</x-app-shell>
