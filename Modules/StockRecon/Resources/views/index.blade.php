{{--
    The stock recon centre, hub.

    One press does the whole job: the balancing AND the exception report come
    out of the same preview, because they are two readings of one pass over the
    chain. Making them two buttons would be two passes that can disagree.
--}}
<x-app-shell title="Stock recon centre">
    {{-- The standing explanation — the invariant and what a commit does — sits
         behind the info button beside the title (ZRP, 4 Oct 2026: the users
         skipped every paragraph on this screen). Nothing about THIS visit goes
         in there: a refusal, a discard and an open run stay on the page. --}}
    <x-page-head eyebrow="Control · stock" title="Stock recon centre">
        <x-slot:info>
            <p>A shift's variance is <code>POS &minus; (Open + Issued &minus; Close)</code>, and one shift's
               closing <em>is</em> the next shift's opening. Balancing moves a closing count, which shifts
               variance from one shift to the next: it decides <strong>who carries</strong> a loss and cannot
               make it smaller. A window that ends <strong>over</strong> sold more than it received: it still
               balances, and the over that no count can remove stays on the shift that carries it, reported as
               the missing issue. Shifts that balance against each other share a letter and a colour.</p>
            @if ($stampMode === 'journal')
                <p><strong>Journal mode.</strong> A commit records each amendment in Agora with the prior counts;
                   nothing in PumpIT moves.</p>
            @else
                <p><strong>A commit writes <code>QtyOpen</code> and <code>QtyClose</code> to PumpIT</strong>, only
                   on the ticked rows, re-checking each first. Every prior pair is recorded, so a run can be
                   reversed exactly. Issues and the <code>_Original</code> columns are never touched.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.reports.index') }}">Reports</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('refusal'))
        {{-- Open by default: a refusal is the answer, not context for one. --}}
        <x-notice tone="stop" title="The balancing could not run" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    @if (session('discarded') !== null)
        <x-notice tone="info"
                  :title="session('discarded') === 0 ? 'Nothing to discard' : session('discarded').' '.Str::plural('preview', session('discarded')).' discarded — committed runs kept'"
                  style="margin-bottom:16px" />
    @endif

    @include('stockrecon::partials.open-run')

    <x-card title="Balance a period">
        <x-slot:info>
            <p>The preview reads the recon lines in PumpIT and writes nothing there. A branch-month takes a
               couple of seconds.</p>
            <p>Both ends of the period are anchors — the first opening and the last closing are believed — so
               the period fixes the residual short before anything else happens.</p>
            @if ($excluded !== [])
                <p>Left out everywhere: areas grouped
                   @foreach ($excluded as $group)<code>{{ $group }}</code>@if (! $loop->last), @endif @endforeach
                   — lotto, airtime and electricity are virtual products with no issues and no count.</p>
            @endif
        </x-slot:info>
        <form method="POST" action="{{ route('app.stockrecon.preview') }}" data-loader="Loading the chains and balancing them…">
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
                    </div>
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @else
                    {{-- Submits on change, because the AREA list belongs to the
                         site: offering last site's areas against this one is a
                         run that comes back empty for a reason nobody can see. --}}
                    <x-field name="branch_id" label="Site"
                             :choices="$branches->pluck('Name', 'BranchId')->all()"
                             :value="old('branch_id', $branchId ?: null)"
                             info="Trading sites only — the administrative entities count no stock." />
                @endif

                {{-- The areas belong to the site, so the list follows the
                     site selector — in the browser, because reloading the page
                     here would throw away the dates and the run name already
                     typed. Every site's areas are rendered once: area numbers
                     repeat across sites, which is why the choices arrive as a
                     LIST rather than a value-keyed map. With no JavaScript
                     every area is offered and the procedure refuses one that
                     is not configured at the chosen site, by name. See
                     linked-select.js.

                     The label is the description ALONE. The AreaGroup used to
                     be appended, so the list read `Bakery OK - Food Items`, and
                     it earned nothing: every counted area's description is
                     already unique within its site, across all 25, so the
                     suffix disambiguated nothing and the widest thing in the
                     list was a word nobody was choosing by. Ryan, 17 Sep 2026.
                     The group is still read in areas(), to drop the excluded
                     groups.

                     NOTHING EXPLANATORY GOES INSIDE THE :choices ATTRIBUTE.
                     A PHP block comment there is still inside an HTML attribute
                     delimited by double quotes, so one quote mark in the prose
                     ends the attribute and spills the rest of the expression
                     onto the page as text. That is exactly what shipped on
                     17 Sep and what took the Counting area field off the form
                     on live. Comments belong here, outside the tag. --}}
                <x-field name="area_no" label="Counting area" linked="branch_id"
                         :value="old('area_no', $areaNo)"
                         :choices="collect([['value' => 0, 'label' => 'Every area at this site']])
                            ->concat($areas->map(fn ($a) => [
                                'value' => $a->AreaNo,
                                'when' => $a->BranchId,
                                'label' => $a->AreaDescription,
                            ]))->all()"
                         info="One area at a time is the working habit: it keeps the proposals short enough to read before committing." />

                {{-- A MONTH, THEN ONE WEEK INSIDE IT, not free dates (ZRP,
                     4 Oct 2026; one week only since 7 Oct 2026 — Ryan: "only
                     let the users pick one week at a time"). Monday–Sunday
                     weeks — the client's pack reviews those — cut at the
                     month's edge; ReconPeriods builds them. The week select IS
                     `from`, following the month through linked-select.js as the
                     area follows the site; the request works out the week's
                     end (BalancePreviewRequest::to()). --}}
                <x-field name="month" label="Month" :choices="$months" :value="old('month', $month)" />

                <x-field name="from" label="Week" linked="month"
                         :value="old('from', $from)"
                         :choices="collect($weeks)->flatMap(fn ($ws, $m) => collect($ws)->map(fn ($w) => [
                             'value' => $w['from'], 'when' => $m, 'label' => $w['label'],
                         ]))->values()->all()"
                         info="One week at a time, Monday to Sunday, cut at the month's edge." />

                {{-- What to call it. A clerk previews the same period several
                     times while tightening the caps, and "August Hot Foods,
                     tight caps" is how they find the one they meant an hour
                     later. Optional: an unnamed run is listed by its period. --}}
                <x-field name="note" label="Name this run" :value="old('note')"
                         info="Optional. Yours to find it by on the run list." />
            </div>

            <details class="params-extra" @if (old('options')) open @endif>
                <summary>Options ({{ count($options) }})</summary>
                <div class="field-row" style="margin-top:12px">
                    @foreach ($options as $name => $option)
                        <x-field :name="'options['.$name.']'"
                                 :label="$option['label']"
                                 :info="$option['help']"
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
                <form method="POST" action="{{ route('app.stockrecon.clear') }}" data-loader="Clearing previews…"
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
