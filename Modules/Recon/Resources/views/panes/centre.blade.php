{{--
    The recon centre — one area, one site, one period, three tabs.

    ZP's ask, through Ryan on 23 Sep 2026: "consolidate the run types into a
    single centre". Choose the site and the dates once; the form folds to a
    line; the automatic balancing runs; Suggestions and Manual match sit in
    tabs beside it with the scope already passed. Ryan left the shape to us,
    with one wish — manual entries in the same load as the run.

    THE TABS ARE LAZY. Each is a fragment its own route renders (the run's
    answer, the suggestion list, the two-pane pair-by-hand), fetched the first
    time it is opened, so a site-month costs only what the clerk looks at: the
    suggestion algorithm is not run for somebody who only wanted the manual
    match. Every press inside a tab — execute, reverse, match, force — carries
    the scope and the tab, and comes back HERE, to the same tab, with the
    other tabs fetched fresh on the next open. A bulk press that runs in the
    page (Match all strong) marks the others stale instead.

    The old Suggestions and Manual match pages still answer at their own URLs,
    for every link already out there, and each offers the way back here.
--}}
@php
    $site = $branches->firstWhere('BranchId', $branchId)?->Name ?? ($branchId ? 'Site '.$branchId : null);
    $period = \Illuminate\Support\Carbon::parse($from)->format('j M').' – '.\Illuminate\Support\Carbon::parse($to)->format('j M Y');
    // What every tab's fragment is asked with, so its forms know the way back.
    $query = $centreScope + ['centre' => 1] + ($run ? ['run' => $run->Id] : []);
@endphp

@if (session('centreDone'))
    <x-notice tone="info" title="Matched" style="margin-bottom:16px">
        <p>{{ session('centreDone') }}
           @if (session('centreRun'))<a href="{{ session('centreRun') }}">Open the run</a> — it can be reversed from there.@endif</p>
    </x-notice>
@endif

@if (session('centreRefusal'))
    <x-notice tone="stop" title="Nothing was matched" style="margin-bottom:16px">
        <p>{{ session('centreRefusal') }}</p>
    </x-notice>
@endif

@if (session('executed'))
    @php($blocked = collect(session('executedLines', []))->where('State', 'blocked'))
    <x-notice :tone="in_array(session('executedCode'), ['COMMITTED', null], true) && $blocked->isEmpty() ? 'info' : 'warn'"
              :title="session('executed')" style="margin-bottom:16px">
        @if ($blocked->isNotEmpty())
            <p>{{ $blocked->count() }} {{ Str::plural('batch', $blocked->count()) }} skipped rather than stamped
               over — the estate has moved since the preview:</p>
            <ul>
                @foreach ($blocked as $row)
                    <li><code>{{ $row['KeyRef'] ?? '—' }}</code> — {{ $row['Reason'] }}</li>
                @endforeach
            </ul>
        @endif
    </x-notice>
@endif

@if (! $scoped)
    @include('recon::partials.open-run')

    <x-card title="What to reconcile"
            sub="Choose the site and the period once. The automatic balancing runs, and the suggestions and the manual match open beside it over the same scope.">
        @include('recon::partials.centre-scope-form')
    </x-card>

    <p class="muted" style="margin-top:16px">
        Everything previewed in this area is on
        <a href="{{ route('app.recon.runs', [$area['key']] + $scope) }}">the Runs tab</a> — yours by default,
        everyone's on request, with filters and an extract.
    </p>
@else
    <x-scope-line style="margin-bottom:16px"
                  :parts="[$site, $period, $run ? 'Run #'.$run->Id : 'Not previewed yet']">
        @include('recon::partials.centre-scope-form')
    </x-scope-line>

    {{-- No card around the tabs: each fragment brings its own, and a card
         inside a card is a border inside a border. --}}
    <x-tabs :items="$centreTabs" :active="$centreTab" query="tab" label="Reconcile this site" class="centre-tabs">
            <x-tab-panel key="auto" :src="$run ? route('app.recon.run.panel', [$run] + $query) : null">
                @if ($run)
                    {{-- The fallback with scripting off, and what shows until
                         the fragment lands. --}}
                    <p class="tabpane-fallback">
                        <a href="{{ route('app.recon.run', $run) }}">Open run #{{ $run->Id }}</a>
                    </p>
                @else
                    <div class="tabpane-pad">
                        <x-empty-state title="Not balanced yet">
                            <p>This site and period have no open preview of yours. Balancing records a run —
                               reproducible, and reversible once executed — so it is a press, not something
                               this page does on its own every time it is opened.</p>
                            <form method="POST" action="{{ route('app.recon.preview') }}" style="margin-top:12px"
                                  data-loader="Balancing {{ $area['label'] }}…">
                                @csrf
                                <input type="hidden" name="area" value="{{ $area['key'] }}">
                                <input type="hidden" name="centre" value="1">
                                <input type="hidden" name="branch_id" value="{{ $branchId }}">
                                <input type="hidden" name="from" value="{{ $from }}">
                                <input type="hidden" name="to" value="{{ $to }}">
                                <button type="submit" class="btn-primary">Run auto balancing</button>
                            </form>
                        </x-empty-state>
                    </div>
                @endif
            </x-tab-panel>

            @if (collect($centreTabs)->contains('key', 'suggest'))
                <x-tab-panel key="suggest" :src="route('app.recon.suggest', [$area['key']] + $query)">
                    <p class="tabpane-fallback">
                        <a href="{{ route('app.recon.suggest', [$area['key']] + $centreScope) }}">Open the suggestions</a>
                    </p>
                </x-tab-panel>
            @endif

            <x-tab-panel key="match" :src="route('app.recon.match', [$area['key']] + $query)">
                <p class="tabpane-fallback">
                    <a href="{{ route('app.recon.match', [$area['key']] + $centreScope) }}">Open the manual match</a>
                </p>
            </x-tab-panel>
    </x-tabs>
@endif
