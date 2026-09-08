{{--
    The stock recon centre, hub.

    One press does the whole job: the balancing AND the exception report come
    out of the same preview, because they are two readings of one pass over the
    chain. Making them two buttons would be two passes that can disagree.
--}}
<x-app-shell title="Stock recon centre">
    <x-page-head
        eyebrow="Control · stock"
        title="Stock recon centre"
        blurb="Re-cut the shift closing counts so a count that was carried forward stops reading as a
               loss, and report what no amendment can fix. The preview reads the customer's recon lines
               and changes nothing.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.reports.index') }}">Reports</a>
        </x-slot:actions>
    </x-page-head>

    {{-- The one sentence everything on this screen follows from. It is a
         thesis rather than a warning, so it leads rather than folds. --}}
    <x-notice tone="info" title="{{ __('stockrecon::stockrecon.invariant') }}" style="margin-bottom:16px">
        <p>A shift's variance is <code>POS &minus; (Open + Issued &minus; Close)</code>, and one shift's
           closing count <em>is</em> the next shift's opening. Raising a closing by <em>d</em> lifts that
           shift's variance by <em>d</em> and drops the next one's by exactly <em>d</em>, so over the
           window everything cancels except at the two ends. Balancing decides which shift carries the
           loss; it cannot make the loss smaller.</p>
        <p>That is why a window ending <strong>over</strong> is the finding rather than a rounding
           problem: more was sold than the books ever received, and no set of closing counts changes it.
           Those chains are reported, never balanced.</p>
    </x-notice>

    @if ($stampMode === 'journal')
        <x-notice tone="warn" collapsible title="Amendments are recorded here, not written to PumpIT"
                  style="margin-bottom:16px">
            <p>A commit records every amendment in <code>agora.StockReconAmendment</code> — the shift, the
               prior pair, the new pair — and nothing in PumpIT moves. The extract from a committed run is
               then the worklist an admin applies by hand.</p>
            <p>Nothing else is different. The arithmetic, the ticks, the confirmation and the reversal are
               the same in both modes.</p>
        </x-notice>
    @else
        {{-- Live. The reader has to know this BEFORE they press, so it does not
             fold — a collapsible warning about writing to the customer's live
             ERP is a warning somebody has to open to learn exists. --}}
        <x-notice tone="stop" title="Committing amends the counts in PumpIT" style="margin-bottom:16px">
            <p>A commit writes <code>QtyOpen</code> and <code>QtyClose</code> back to
               <code>STK_StockReconLine</code> in the customer's live database. It acts on the ticked rows
               and no others, re-checks every one against the source first, and skips anything whose counts
               have moved since the preview.</p>
            <p><strong>It is reversible.</strong> Each shift's prior pair is recorded before anything moves,
               so a reversal puts back precisely what was changed — not the original counts, so an earlier
               hand amendment is not thrown away with it. The procedure this replaces can undo nothing.</p>
            <p>Two things it will not touch: <code>QtyIssued</code>, because amending an issue changes the
               window's total rather than redistributing it, and the <code>_Original</code> columns, which
               are what make a re-preview propose the same amendment rather than one on top of the last.</p>
        </x-notice>
    @endif

    @if (session('refusal'))
        {{-- Open by default: a refusal is the answer, not context for one. --}}
        <x-notice tone="stop" title="The balancing could not run" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    @if (session('discarded') !== null)
        <x-notice tone="info"
                  :title="session('discarded') === 0 ? 'Nothing to discard' : session('discarded').' '.Str::plural('preview', session('discarded')).' discarded'"
                  style="margin-bottom:16px">
            <p>Previews only. Nothing in the customer's databases was touched, and any run that had been
               committed was kept — a committed run is the only record of what it amended.</p>
        </x-notice>
    @endif

    @include('stockrecon::partials.open-run')

    <x-card title="Balance a period"
            sub="Reads agora.vw_StockReconLine over PumpIT and writes nothing. Two seconds for a branch-month.">
        <form method="POST" action="{{ route('app.stockrecon.preview') }}">
            @csrf

            <div class="field-row">
                {{-- The branches component lives on the result set, not in the
                     chrome (feature-rules §3.3). In the branch workspace the
                     site is already pinned, so it states it rather than
                     offering a choice that does not exist. --}}
                @if ($pinned)
                    <div class="field">
                        <label>Site</label>
                        <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
                        <p class="field-help">Your workspace is pinned to this site.</p>
                    </div>
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @else
                    {{-- Submits on change, because the AREA list belongs to the
                         site: offering last site's areas against this one is a
                         run that comes back empty for a reason nobody can see. --}}
                    <x-field name="branch_id" label="Site"
                             :choices="$branches->pluck('Name', 'BranchId')->all()"
                             :value="old('branch_id', $branchId ?: null)"
                             help="Trading sites only — the administrative entities count no stock. Choosing one loads its counting areas." />
                @endif

                {{-- The areas belong to the site, so the list follows the
                     site selector — in the browser, because reloading the page
                     here would throw away the dates and the run name already
                     typed. Every site's areas are rendered once: area numbers
                     repeat across sites, which is why the choices arrive as a
                     LIST rather than a value-keyed map. With no JavaScript
                     every area is offered and the procedure refuses one that
                     is not configured at the chosen site, by name. See
                     linked-select.js. --}}
                <x-field name="area_no" label="Counting area" linked="branch_id"
                         :value="old('area_no', $areaNo)"
                         :choices="collect([['value' => 0, 'label' => 'Every area at this site']])
                            ->concat($areas->map(fn ($a) => [
                                'value' => $a->AreaNo,
                                'when' => $a->BranchId,
                                'label' => $a->AreaDescription.($a->AreaGroup ? ' — '.$a->AreaGroup : ''),
                            ]))->all()"
                         help="One area at a time is the working habit: the proposals table is deliberately not paged, because a paged commit form is a form that writes rows nobody looked at." />

                <x-field name="from" label="From" type="date" :value="old('from', $from)"
                         help="Both ends are anchors — the first opening and the last closing are believed — so the window fixes the residual short before anything else happens." />

                <x-field name="to" label="To" type="date" :value="old('to', $to)" />

                {{-- What to call it. A clerk previews the same period several
                     times while tightening the caps, and "August Hot Foods,
                     tight caps" is how they find the one they meant an hour
                     later. Optional: an unnamed run is listed by its period. --}}
                <x-field name="note" label="Name this run" :value="old('note')"
                         help="Optional. Yours to find it by — it appears on the run list and on the run itself." />
            </div>

            <details class="params-extra" @if (old('options')) open @endif>
                <summary>Plausibility caps ({{ count($options) }})</summary>
                <p class="field-help" style="margin:8px 0 12px">
                    Every one of these <strong>blocks</strong> rather than bends: a chain that needs more
                    than a cap allows is reported untouched, never amended half way. The defaults are the
                    loose end of each, so the first thing you see is what the method would do — tighten
                    one on purpose, after reading the blocked list.
                </p>
                <div class="field-row">
                    @foreach ($options as $name => $option)
                        <x-field :name="'options['.$name.']'"
                                 :label="$option['label']"
                                 :help="$option['help']"
                                 :type="$option['type'] === 'decimal' ? 'number' : $option['type']"
                                 :min="$option['min'] ?? null"
                                 :max="$option['max'] ?? null"
                                 :step="$option['step'] ?? null"
                                 :value="old('options.'.$name, $option['default'])" />
                    @endforeach
                </div>
            </details>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Preview the balancing</button>
                <span class="field-help">Read-only. Nothing in PumpIT changes.</span>
            </div>
        </form>

        <x-slot:foot>
            @if ($excluded !== [])
                <p class="field-help">
                    Excluded throughout: areas whose group is
                    @foreach ($excluded as $group)<code>{{ $group }}</code>@if (! $loop->last), @endif @endforeach.
                    Lotto, airtime and electricity are virtual products — no issue is ever captured and no
                    physical count is ever taken — so they read as a permanent, enormous net over and
                    cannot be reconciled as stock, on the same reasoning that keeps fuel out of the pack.
                </p>
            @endif
        </x-slot:foot>
    </x-card>

    {{--
        The runs list, as a real grid: filterable, sortable, extractable and
        scoped to the person who made the runs. Yours unless you ask for
        everyone's — and the switch is <x-tabs> in link mode, carrying `?scope=`
        like every other scope in Agora, so a view of the list is something you
        can send to somebody.
    --}}
    @php($mine = \Modules\StockRecon\Grids\StockReconRunGrid::wantsOwnRunsOnly())

    <x-card title="Runs"
            :sub="$mine ? 'The balancing previews you have made' : 'Every balancing preview, whoever made it'"
            flush>
        <x-slot:actions>
            <x-tabs label="Whose runs"
                    :active="$mine ? 'mine' : 'all'"
                    :items="[
                        ['key' => 'mine', 'label' => 'Mine', 'href' => route('app.stockrecon.index')],
                        ['key' => 'all', 'label' => 'Everyone', 'href' => route('app.stockrecon.index', ['scope' => 'all'])],
                    ]" />

            @if ($context->id() !== null)
                <form method="POST" action="{{ route('app.stockrecon.clear') }}"
                      data-confirm="Discard every preview you have made for this site?"
                      data-confirm-text="Previews only. Nothing in PumpIT is affected — a preview is a record of a read — and any run you have committed is kept."
                      data-confirm-action="Discard previews" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost">Clear previews</button>
                </form>
            @endif
        </x-slot:actions>

        <x-data-grid :grid="$grid" :branches="$pinned ? null : $branches" />
    </x-card>
</x-app-shell>
