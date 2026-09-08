{{--
    The one development surface: the component library, the tokens, the type
    scale and the number parity table.

    Served at /dev/components, and ALSO at /dev/theme — one page, two URLs, not
    two pages. /dev/theme is kept because tests/e2e/format.spec.js loads it five
    times and is the only guard on `App\Support\Format` agreeing with
    `resources/js/format.js`; renaming the URL that guard depends on, in a lane
    that cannot run a browser to prove the rename is clean, is not a trade worth
    making. There is deliberately no second gallery beside this one.

    Standalone rather than inside <x-app-shell>: the shell is fed by
    ShellComposer, which wants a signed-in user and a branch context, and a
    gallery nobody can open without a session is a gallery nobody opens. It
    borrows the app bar for the theme toggle alone, because "does this read in
    dark" is a question this page exists to answer by being looked at.

    Nothing here writes component markup. That is the rule the page is about,
    and scripts/check-components.sh holds it to it.
--}}
<!doctype html>
{{-- $theme is validated in the controller against the two values it may hold.
     <x-app-shell> already did this and this page did not, so a cookie holding
     anything else — a stale encrypted value, or something a person typed —
     was written straight into the attribute and stamped the document with a
     theme that does not exist. Three states, and only three: light, dark, or
     unstamped and following the system. --}}
<html lang="en" @if($theme) data-theme="{{ $theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Components · Agora</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>

<header class="appbar">
    <a class="brand" href="{{ url('/app') }}">
        <span class="brand-text">
            <span class="wm">AG<em>O</em>RA</span>
            <span class="sub">Component gallery</span>
        </span>
    </a>
    <nav class="primary"></nav>
    <div class="tools">
        <button class="iconbtn" id="themebtn" aria-label="Switch light / dark" title="Switch light / dark">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.2 9.6A5.6 5.6 0 0 1 6.4 2.8a5.6 5.6 0 1 0 6.8 6.8Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        </button>
    </div>
</header>

<main class="shell-main">
    <div class="shell-in">

        <x-page-head
            eyebrow="Development"
            title="Components"
            blurb="Every component, every variant, the props each takes — and under them the tokens, the type scale and the number formats they are all built from. Check here before you build anything: the point of this page is that nobody writes a second version of something that already exists." />

        <x-notice tone="info">
            The data below is fixtures held in <code>StyleguideController</code>, not a query — a component never
            touches the database. The awkward cases are deliberate: a null figure, an empty list, a step with no
            destination, a movement too small to be a trend. Use the toggle in the bar to check both themes, and
            narrow the window to 375px for the mobile pass.
        </x-notice>

        <nav class="gal-nav" aria-label="Jump to a section">
            @foreach ($groups as $group => $blurb)
                <a href="#g-{{ Str::slug($group) }}">{{ $group }}</a>
            @endforeach
            <a href="#g-pending">Not built yet</a>
            <a href="#g-tokens">Tokens</a>
            <a href="#g-type">Type</a>
            <a href="#g-numbers">Numbers</a>
        </nav>

        {{-- ============================================================ Chrome --}}
        <h2 id="g-chrome" class="sg-group">Chrome</h2>

        <x-core::gallery-entry name="x-crumb">
            <div class="gal-variant">
                <p class="eyebrow">Three parts, the last one current and unlinked</p>
                <x-crumb :parts="$fixtures['crumb']" />
            </div>
            <div class="gal-variant">
                <p class="eyebrow">One part — a root page still says where it is</p>
                <x-crumb :parts="['Zululand Retail &amp; Petroleum']" />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-workspace-switch">
            <div class="gal-variant" style="background: var(--chrome); padding: 14px;">
                <p class="eyebrow" style="color: var(--chrome-muted);">On the chrome bar, where it lives</p>
                <x-workspace-switch :workspaces="$fixtures['workspaces']" current="ho" />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-page-head">
            <div class="gal-variant">
                <p class="eyebrow">Eyebrow, title, blurb and an action</p>
                <x-page-head eyebrow="Head office" title="What does not reconcile"
                             blurb="Every open item across the group with an owner, an age and a rand value.">
                    <x-slot:actions><button type="button" class="btn primary">Extract</button></x-slot:actions>
                </x-page-head>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Title alone</p>
                <x-page-head title="Today" />
            </div>
        </x-core::gallery-entry>

        {{-- ============================================================ Structure --}}
        <h2 id="g-structure" class="sg-group">Structure</h2>

        <x-core::gallery-entry name="x-card">
            <div class="gal-variant">
                <p class="eyebrow">Head, sub, actions, body</p>
                <x-card title="Daily banking" sub="4 September · 25 sites">
                    <x-slot:actions><button type="button" class="btn sm">Extract</button></x-slot:actions>
                    <p>A card is the unit a screen is built from. Its head carries the title, one line of context,
                       and whatever controls belong to this panel rather than to the page.</p>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Collapsible, remembering the reader's choice — provenance starts closed</p>
                <x-card title="What this run was given" sub="The arguments, exactly as the procedure received them"
                        collapsible remember="gallery-demo">
                    <p>Opened or closed, the state is kept for this browser by <code>disclosure.js</code>.
                       Native <code>&lt;details&gt;</code> forgets on every reload.</p>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Flush, for something that goes edge to edge</p>
                <x-card title="Needs a decision" sub="Waiting on this branch" flush>
                    <x-decision-list :items="$fixtures['decisions']" />
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">No title — a plain panel</p>
                <x-card><p>Without a title there is no head, and the body is the whole card.</p></x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-modal">
            <div class="gal-variant">
                <p class="eyebrow">A native dialog. The button is the whole API — <code>data-modal-open</code></p>
                <p><button type="button" class="btn-ghost" data-modal-open="gallery-modal">Open the dialog</button></p>
                <x-modal id="gallery-modal" title="Extraction rule">
                    <p>A modal interrupts, so it is for a form somebody must finish or abandon. A row that
                       merely wants to show more of itself belongs in <code>data-row-detail</code>, which
                       expands in place and keeps the list visible.</p>
                    <p class="field-help">Escape closes it, so does the backdrop, and focus goes back where
                       it came from — all of that is the browser, not us.</p>
                </x-modal>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-kpi-strip">
            <div class="gal-variant">
                <p class="eyebrow">Four tiles, with stripes and comparisons — and one figure the system does not have</p>
                <x-kpi-strip>
                    @foreach ($fixtures['kpis'] as $kpi)
                        <x-kpi :label="$kpi['label']" :value="$kpi['value']"
                               :compare="$kpi['compare']" :tone="$kpi['tone'] ?? 'neutral'"
                               :stripe="$kpi['stripe'] ?? null" />
                    @endforeach
                </x-kpi-strip>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-kpi">
            <div class="gal-variant">
                <p class="eyebrow">A unit, a whole-tile link, and the slot the sparkline goes in</p>
                <x-kpi-strip>
                    <x-kpi label="Fuel margin" :value="\App\Support\Format::n(175.25, 2)" unit="c/&#8467;"
                           :compare="'budget '.\App\Support\Format::n(171, 2)" stripe="s1" />
                    <x-kpi label="Open exceptions" :value="\App\Support\Format::n(9)"
                           compare="click through to the register" tone="crit" stripe="crit" href="#g-queues" />
                    <x-kpi label="Shop turnover" :value="\App\Support\Format::rk(2208437)" stripe="s2">
                        <x-slot:spark>
                            <div class="gal-slot" style="padding: 4px; font-size: 10.5px;">&lt;x-sparkline&gt; · Lane D</div>
                        </x-slot:spark>
                    </x-kpi>
                </x-kpi-strip>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-statstrip">
            <div class="gal-variant">
                <p class="eyebrow">Inside the card it describes, not at the top of the page</p>
                <x-card title="ABSA MarkOff — run 1284" flush>
                    <x-statstrip :stats="$fixtures['stats']" />
                </x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-tabs">
            <div class="gal-variant">
                <p class="eyebrow">Panel mode — remembered between visits, and walkable on the arrow keys</p>
                <x-card flush>
                    <x-tabs :items="$fixtures['tabs']" active="imported" persist="gallery-import" label="Import stage">
                        <x-tab-panel key="raw">
                            <x-empty-state title="Raw file"
                                           text="The file exactly as the branch sent it, before anything was stripped." />
                        </x-tab-panel>
                        <x-tab-panel key="stripped">
                            <x-empty-state title="Stripped file"
                                           text="What survived the parser: 2 388 of 2 411 lines." />
                        </x-tab-panel>
                        <x-tab-panel key="imported">
                            <x-empty-state title="Imported"
                                           text="What reached the database. This pane is the one the server marked active, so it is on screen before any script runs." />
                        </x-tab-panel>
                    </x-tabs>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Link mode — no JavaScript at all, and each tab is a place you can send someone</p>
                <x-card flush>
                    <x-tabs active="g-structure" label="Gallery sections" :items="[
                        ['key' => 'g-chrome', 'label' => 'Chrome', 'href' => '#g-chrome'],
                        ['key' => 'g-structure', 'label' => 'Structure', 'href' => '#g-structure'],
                        ['key' => 'g-data', 'label' => 'Data', 'href' => '#g-data'],
                    ]" />
                </x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-tab-panel">
            <div class="gal-variant">
                <p class="eyebrow">Rendered by the panel-mode example above — one pane, keyed to a tab</p>
                <x-notice tone="info">
                    A pane takes <code>active</code> off its parent with <code>@@aware</code>, so a page lists its
                    panes without repeating which one is showing. The inactive ones carry <code>hidden</code> from
                    the server, which is what makes the first paint correct with scripting off.
                </x-notice>
            </div>
        </x-core::gallery-entry>

        {{-- ============================================================ Data --}}
        <h2 id="g-data" class="sg-group">Data</h2>

        <x-core::gallery-entry name="x-chip">
            <div class="gal-variant">
                <p class="eyebrow">Five tones, each with its dot</p>
                <p>
                    <x-chip>Neutral</x-chip>
                    <x-chip tone="good">Reconciled</x-chip>
                    <x-chip tone="warn">Review</x-chip>
                    <x-chip tone="serious">Escalated</x-chip>
                    <x-chip tone="crit">Not banked</x-chip>
                </p>
                <p class="eyebrow">Without the dot, where the chip is a label rather than a state</p>
                <p>
                    <x-chip tone="neutral" :dot="false">Finance</x-chip>
                    <x-chip tone="neutral" :dot="false">Operations</x-chip>
                </p>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-delta">
            <div class="gal-variant">
                <p class="eyebrow">Every case, including the two that are not a trend</p>
                <table class="gal-props">
                    <thead><tr><th>Rendered</th><th>Call</th><th>What it is</th></tr></thead>
                    <tbody>
                        @foreach ($fixtures['deltas'] as $d)
                            <tr>
                                <td><x-delta :value="$d['value']" :invert="$d['invert']" /></td>
                                <td class="gal-type">value={{ $d['value'] ?? 'null' }} invert={{ $d['invert'] ? 'true' : 'false' }}</td>
                                <td>{{ $d['what'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-empty-state">
            <div class="gal-variant">
                <p class="eyebrow">With a headline — a designed answer, not a gap</p>
                <x-card flush>
                    <x-empty-state title="Pick a Z-read"
                                   text="Choose a row on the left to set its shift and the person who worked it — or press Auto-allocate and confirm what it proposes." />
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">One line, for inside a list or a table foot</p>
                <x-empty-state text="Nothing to show — this is what an empty result looks like." />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-table">
            <div class="gal-variant">
                <p class="eyebrow">A result set with its procedure named and its count stated</p>
                <x-table :procedure="'agora.usp_Cash_GridDailyBanking'" :count="3" :total="41">
                    <x-slot:head>
                        <tr><th>Site</th><th>Reference</th><th class="num">Declared</th><th class="num">Banked</th></tr>
                    </x-slot:head>
                    @foreach ($fixtures['tableRows'] as $row)
                        <tr>
                            <td>{{ $row['site'] }}</td>
                            <td class="mono">{{ $row['ref'] }}</td>
                            <td class="num">{{ \App\Support\Format::r($row['declared']) }}</td>
                            <td class="num">{{ \App\Support\Format::r($row['banked']) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Empty — a designed answer, not a gap</p>
                <x-table :count="0" empty="The procedure ran and found nothing in this period. That is an answer, not a failure." />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-compare">
            <div class="gal-variant">
                <p class="eyebrow">Two readings, and which one is actually in force</p>
                <x-compare live="right"
                           left-title="The customer's row"
                           right-title="Agora's override — in force">
                    <x-slot:left>
                        <dl>
                            <dt>Bank</dt><dd>43 / 47</dd>
                            <dt>Deposit</dt><dd>1 / 10</dd>
                            <dt>Filter</dt><dd>—</dd>
                        </dl>
                    </x-slot:left>
                    <x-slot:right>
                        <dl>
                            <dt>Bank</dt><dd>28 / 32</dd>
                            <dt>Deposit</dt><dd>1 / 10</dd>
                            <dt>Why</dt><dd>Started past the end of every narrative</dd>
                        </dl>
                    </x-slot:right>
                </x-compare>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-two-pane-recon">
            <div class="gal-variant">
                <p class="eyebrow">Coloured only where the reference is on BOTH sides — 125 and 200 pair, 300 and 400 do not</p>
                <x-two-pane-recon :bank="$fixtures['recon']['bank']"
                                  :mops="$fixtures['recon']['mops']"
                                  :summary="$fixtures['recon']['summary']"
                                  key-label="Batch" />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-sqlbox">
            <div class="gal-variant">
                <p class="eyebrow">Closed by default — on a screen that has an answer, the SQL is provenance</p>
                <x-sqlbox procedure="agora.usp_Cash_GridDailyBanking">{{ $fixtures['sql'] }}</x-sqlbox>
            </div>
        </x-core::gallery-entry>

        {{-- ============================================================ Parameters --}}
        <h2 id="g-parameters" class="sg-group">Parameters</h2>

        <x-core::gallery-entry name="x-param">
            <div class="gal-variant">
                <p class="eyebrow">The compact form of the same control, for a run bar</p>
                <div class="field-row">
                    <x-param name="branch" label="Site" :choices="['' => 'Every site', '18' => 'Elephant Coast']" />
                    <x-param name="from" label="From" type="date" value="2026-08-01" />
                    <x-param name="to" label="To" type="date" value="2026-08-31" />
                </div>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-field">
            <div class="gal-variant">
                <p class="eyebrow">The full-size form field — a text input, a date, a select and a checkbox</p>
                <div class="field-row">
                    <x-field name="q" label="Search" value="" placeholder="Site, reference, name…"
                             help="Matches anywhere in the row, not only at the start." />
                    <x-field name="from" label="From" type="date" value="2026-09-01" />
                    <x-field name="area" label="Recon area" :choices="['' => 'All areas', 'absa' => 'ABSA MarkOff']" />
                    <x-field name="commit" label="Commit" type="bool" placeholder="Write the matches" />
                </div>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-params">
            <div class="gal-variant">
                <p class="eyebrow">A report's parameters, two greyed because this report ignores them</p>
                <x-card title="Daily Banking Reconciliation" sub="Branch · one day">
                    <x-params legend="Report parameters">
                        <x-param name="branch" label="Branch name" :choices="['' => '<<ALL SITES>>', '8' => 'Caltex Ulundi']" searchable />
                        <x-param name="from" label="From date" type="date" value="2026-08-01" />
                        <x-param name="to" label="To date" type="date" value="2026-08-31" />
                        <x-param name="variance" label="Variance threshold" type="number" value="0.00" step="0.01"
                                 help="Rows inside this are not reported." />
                        <x-param name="region" label="Branch region" :choices="$fixtures['regions']" disabled />
                        <x-param name="output" label="Output" :choices="['grid' => 'GRID', 'csv' => 'Excel extract']" disabled />
                    </x-params>
                    <x-slot:foot>
                        <x-runbar procedure="agora.usp_Cash_GridDailyBanking" status="Ready." type="button">
                            <button type="button" class="btn">Save as scheduled report</button>
                            <button type="button" class="btn">Add to Exco dashboard</button>
                        </x-runbar>
                    </x-slot:foot>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Folded, and remembering that it was folded</p>
                <x-card title="Pump Variance by Grade">
                    <x-params collapsible :open="false" remember="gallery-params" summary="Parameters — 2">
                        <x-param name="grade" label="Grade" :choices="['' => 'All grades', 'ulp95' => 'ULP 95', 'dsl' => 'Diesel 50ppm']" />
                        <x-param name="asat" label="As at" type="date" value="2026-09-04" />
                    </x-params>
                </x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-runbar">
            <div class="gal-variant">
                <p class="eyebrow">In a form, so the button really does disable itself on submit</p>
                <form method="GET" action="{{ url()->current() }}">
                    <x-card flush>
                        <x-slot:foot>
                            <x-runbar action="Execute" procedure="agora.usp_Core_Ping"
                                      status="Returned 394 rows in 0.42 s" />
                        </x-slot:foot>
                    </x-card>
                </form>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Nothing to run</p>
                <x-runbar action="Execute" type="button" disabled status="Choose a branch first." />
            </div>
        </x-core::gallery-entry>

        {{-- ============================================================ Queues --}}
        <h2 id="g-queues" class="sg-group">Queues</h2>

        <x-core::gallery-entry name="x-checklist">
            <div class="gal-variant">
                <p class="eyebrow">Two of four done; the last step has nowhere to go, so it is not a link</p>
                <x-card title="End-of-day capture" sub="2 of 4 complete" flush>
                    <x-checklist :steps="$fixtures['checklist']" />
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Nothing to do</p>
                <x-card flush><x-checklist :steps="[]" /></x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-exception-list">
            <div class="gal-variant">
                <p class="eyebrow">Four severities, expanding in place on Enter or Space</p>
                <x-card title="Exception register" sub="Ranked by severity · open one to read it" flush>
                    <x-exception-list>
                        @foreach ($fixtures['exceptions'] as $ex)
                            <x-exception-row
                                :severity="$ex['severity']" :title="$ex['title']"
                                :detail="$ex['detail'] ?? null" :category="$ex['category']"
                                :site="$ex['site']" :age="$ex['age']" :owner="$ex['owner']"
                                :value="$ex['value'] ?? null" :unit="$ex['unit'] ?? null"
                                :href="$ex['href'] ?? null" />
                        @endforeach
                    </x-exception-list>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Empty — which is the good outcome, and should read like one</p>
                <x-card flush><x-exception-list /></x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-exception-row">
            <div class="gal-variant">
                <p class="eyebrow">One row, open, with everything it can carry</p>
                <x-card flush>
                    <x-exception-list>
                        <x-exception-row open
                            severity="serious"
                            title="ULP 95 dip is 1 480 litres below the meter at Caltex Ulundi"
                            detail="The variance has run in the same direction for six days, which is the shape of a meter drift rather than of a delivery not captured."
                            category="Fuel" site="Caltex Ulundi" age="6 days" owner="Operations"
                            :value="\App\Support\Format::litres(-1480)" unit="dip vs meter" href="#g-queues" />
                    </x-exception-list>
                </x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">A row with a title and a severity, and nothing else known yet</p>
                <x-card flush>
                    <x-exception-list>
                        <x-exception-row severity="warning" title="Two POS categories have no GL mapping" />
                    </x-exception-list>
                </x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-decision-list">
            <div class="gal-variant">
                <p class="eyebrow">Three waiting; the last carries no rand value</p>
                <x-card flush><x-decision-list :items="$fixtures['decisions']" /></x-card>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Nothing waiting</p>
                <x-card flush><x-decision-list :items="[]" /></x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-proposal">
            <div class="gal-variant">
                <p class="eyebrow">Certain — the evidence is the point, not the confidence word</p>
                <x-proposal confidence="certain"
                            headline="Day Shift · Thandeka Mkhize (E1042)"
                            :evidence="[
                                'Operator ID 14 has run till 2 on 47 of the last 50 Day Shifts at this branch.',
                                'The Z-read closed at 14:52, inside the Day Shift window.',
                            ]">
                    <x-slot:actions>
                        <button type="button" class="btn primary">Accept</button>
                        <button type="button" class="btn">Set by hand</button>
                    </x-slot:actions>
                </x-proposal>
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Likely, and review</p>
                <x-proposal confidence="likely" headline="Night Shift · Sipho Zulu (E1180)"
                            :evidence="['Operator ID 22 has run this till on 12 of the last 30 Night Shifts.']" />
                <x-proposal confidence="review" style="margin-top: 12px;"
                            headline="Afternoon Shift · shift unknown"
                            :evidence="['Two operators have run this till on this shift in the last month, 15 times each.']" />
            </div>
            <div class="gal-variant">
                <p class="eyebrow">Manual — an absence of history is itself information</p>
                <x-proposal confidence="manual" eyebrow="The system cannot say"
                            :evidence="[
                                'No history for operator ID 91 on this till.',
                                'It is worth asking why a till operator ID has appeared that the branch has never allocated before.',
                            ]" />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-lib-card">
            <div class="gal-variant">
                <p class="eyebrow">One runnable, one not — and the legacy names each replaces</p>
                <x-card title="Banking &amp; reconciliation" sub="2 of 97 reports" flush>
                    @foreach ($fixtures['library'] as $report)
                        <x-lib-card :name="$report['name']" :desc="$report['desc']" :scope="$report['scope']"
                                    :was="$report['was']" :tags="$report['tags']" :href="$report['href'] ?? null" />
                    @endforeach
                </x-card>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-role-card">
            <div class="gal-variant">
                <p class="eyebrow">A large tappable choice</p>
                <x-role-card label="Branch manager" who="Runs one site" href="#g-queues"
                             detail="Opens on today at your branch: the day-close checklist, what needs a decision, and your open exceptions." />
                <x-role-card label="Auditor" who="Read-only, every site" href="#g-queues"
                             detail="Opens on the exception register. Sees everything and changes nothing." />
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-system-state">
            <div class="gal-variant" style="background: var(--chrome); padding: 18px;">
                <p class="eyebrow" style="color: var(--chrome-muted);">On the sign-in hero, before anyone has signed in</p>
                <x-system-state title="System state · 4 September 2026" :rows="$fixtures['state']" />
            </div>
        </x-core::gallery-entry>

        {{-- ============================================================ Messages --}}
        <h2 id="g-messages" class="sg-group">Messages</h2>

        <x-core::gallery-entry name="x-notice">
            <div class="gal-variant">
                <p class="eyebrow">Three tones, and the fold that keeps the headline visible</p>
                <x-notice tone="info" style="margin-bottom: 12px;">
                    Figures are ex-VAT. With no title this is the mockup's one-line <code>note</code> — the same
                    component, which is why there is no separate <code>&lt;x-note&gt;</code>.
                </x-notice>
                <x-notice tone="warn" title="What this report cannot tell you" style="margin-bottom: 12px;">
                    Deliveries captured after the dip are not in this variance.
                </x-notice>
                <x-notice tone="stop" title="That run cannot be discarded" style="margin-bottom: 12px;">
                    It has already been committed. Reverse it instead — the trail stays.
                </x-notice>
                <x-notice tone="warn" collapsible title="31 lines did not match">
                    Twenty-eight are timing: the bank posted them on the next working day. Three are amounts that
                    appear on neither side, and those are the ones worth a person.
                </x-notice>
            </div>
        </x-core::gallery-entry>

        <x-core::gallery-entry name="x-tip">
            <div class="gal-variant">
                <p class="eyebrow">Hover or tab onto the marked words — one element, shared by the whole page</p>
                <p>
                    The <span data-tip="Dip: what the tank actually holds, measured. <b>Meter</b>: what the pump says it sold." tabindex="0"
                              style="text-decoration: underline dotted; cursor: help;">dip against meter</span>
                    variance is the first thing a site looks at, and
                    <span data-tip="<div class='r'><span>ULP 95</span><span>-1 480 L</span></div><div class='r'><span>Diesel</span><span>+210 L</span></div>" tabindex="0"
                          style="text-decoration: underline dotted; cursor: help;">it is read per grade</span>,
                    never in total. Hidden below 720px: a fixed box on a phone covers the answer it describes.
                </p>
                <x-tip />
            </div>
        </x-core::gallery-entry>

        <x-card title="window.Agora.notify"
                sub="Not a component — the library every alert and confirmation goes through.">
            <p>SweetAlert2 loads on first use, not on every page. Nothing calls native
               <code>alert</code>, <code>confirm</code> or <code>prompt</code> — those block the page, cannot be
               themed, and read as a browser failure rather than as the system asking a question.</p>
            <p>
                <button type="button" class="btn"
                        onclick="window.Agora.notify.toast('Nothing was changed — this is the gallery.')">Toast</button>
                <button type="button" class="btn"
                        onclick="window.Agora.notify.confirm('Reverse this cashup?', { text: 'The entry stays and a reversing entry is written beside it.', action: 'Reverse', danger: true })">Confirm</button>
            </p>
        </x-card>

        {{-- ============================================================ Not built yet --}}
        <h2 id="g-pending" class="sg-group">Not built yet</h2>

        <x-card title="Slots for the components other tasks own"
                sub="Wire the real component in here when it lands — the page is already the shape it needs.">
            <div class="area-grid">
                @foreach ($pending as $item)
                    <div class="gal-slot">
                        <div class="gal-slot-name">&lt;{{ $item['name'] }}&gt;</div>
                        <p style="margin: 6px 0 0;">{{ $item['owner'] }}</p>
                        <p style="margin: 6px 0 0; text-align: left;">{{ $item['what'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card title="What is on this page"
                sub="Generated from the same catalogue that writes docs/components.md, so the two cannot disagree.">
            <p>{{ collect($catalogue)->where('gallery', true)->count() }} components rendered,
               {{ count($pending) }} waiting on another task, and
               {{ collect($catalogue)->pluck('props')->flatten(1)->count() }} props documented.
               Run <code>php artisan agora:components-doc</code> after adding one;
               <code>scripts/check-components.sh</code> fails the build if you forget.</p>
        </x-card>

        {{-- ============================================================ Tokens --}}
        <h2 id="g-tokens" class="sg-group">The theme underneath</h2>

        <x-card title="Colour tokens" sub="Defined once in resources/scss/_tokens.scss. No component may declare a colour of its own.">
            @foreach ($tokens as $group => $names)
                <h3 class="sg-group">{{ $group }}</h3>
                <div class="sg-swatches">
                    @foreach ($names as $name)
                        <div class="sg-swatch">
                            <span class="sg-chip" style="background: var(--{{ $name }})"></span>
                            <code>--{{ $name }}</code>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </x-card>

        <x-card id="g-type" title="Type" sub="Barlow Condensed for display, IBM Plex Sans for text, IBM Plex Mono for codes and figures — all served from Agora, not from a font host.">
            <div class="sg-type">
                <p class="sg-spec">Display · Barlow Condensed 600 · 30px</p>
                <h1 style="font-family: var(--font-display); font-size: 30px;">Weekly Exco trading pack</h1>

                <p class="sg-spec">Display · 18px</p>
                <h2 style="font-family: var(--font-display); font-size: 18px;">Cash and banking exceptions</h2>

                <p class="sg-spec">Body · IBM Plex Sans 400 · 13.5px</p>
                <p style="max-width: 65ch;">The dip must tie to the pump, the Z-read to the cash-up, the declaration to the bank. Every figure on a screen can be opened to the load it came from.</p>

                <p class="sg-spec">Body · 600</p>
                <p style="font-weight: 600;">Ngwelezane Convenience Centre — day not closed</p>

                <p class="sg-spec">Mono · IBM Plex Mono 400 · codes, times and identifiers</p>
                <p style="font-family: var(--font-mono);">BRN_DailyBanking · 2026-09-04 05:13 · ULP 95</p>

                <p class="sg-spec">Uppercase label · 10.5px · .11em</p>
                <p class="eyebrow">Trading sites</p>
            </div>
        </x-card>

        <x-card id="g-numbers" title="Numbers" sub="The PHP helper and its JavaScript twin, on the same inputs. The two columns must match exactly.">
            <p>These are the same figures rendered on the server and in the browser. They are shown side by side because the locales disagree — asked for en-ZA, PHP returns <code>1,234,567.89</code> and JavaScript returns <code>1&nbsp;234&nbsp;567,89</code> — so Agora states the format itself rather than trusting either.</p>
            <div class="table-wrap">
                <table id="parity">
                    <thead>
                        <tr><th>Call</th><th>PHP</th><th>JavaScript</th><th class="num">Match</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr data-fn="{{ $case['fn'] }}" data-args='@json($case['args'])'>
                                <td><code>{{ $case['fn'] }}({{ collect($case['args'])->map(fn ($a) => is_null($a) ? 'null' : $a)->implode(', ') }})</code></td>
                                <td class="php-out">{{ $case['php'] }}</td>
                                <td class="js-out"></td>
                                <td class="num verdict"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</main>

{{--
    The JavaScript half of the parity table.

    It reads the formatter off window.Agora — the module the application
    actually ships — rather than importing resources/js/format.js by URL. That
    import needed format.js to be its own Vite input, which it is not, so
    against built assets `Vite::asset` threw "Unable to locate file in Vite
    manifest" and this whole page returned 500. The page is the only guard on
    App\Support\Format agreeing with its twin, so the guard was the thing that
    was broken — and browser tests are not in `composer check`, which is why it
    went unnoticed. tests/Feature/Components/GalleryTest.php now fails if the
    page stops rendering.

    Module scripts are deferred and run in document order, so app.js has
    already assigned window.Agora by the time this executes.
--}}
<script type="module">
    const format = window.Agora.format;

    document.querySelectorAll('#parity tbody tr').forEach((row) => {
        const fn = row.dataset.fn;
        const args = JSON.parse(row.dataset.args);
        const js = format[fn](...args);

        row.querySelector('.js-out').textContent = js;

        const php = row.querySelector('.php-out').textContent;
        const same = php === js;
        const cell = row.querySelector('.verdict');
        cell.textContent = same ? 'yes' : 'NO';
        cell.className = 'num verdict ' + (same ? 'ok' : 'bad');
    });
</script>

</body>
</html>
