<x-app-shell :title="$area['label'].' — every site'">
    <x-page-head
        eyebrow="Auto reconciliation"
        :title="($group->Note ?: $area['label']).' — every site'"
        :blurb="'One press across '.$group->BranchCount.' trading '.Str::plural('site', $group->BranchCount)
                .', '.$group->FromDate->toDateString().' to '.$group->ToDate->toDateString()
                .'. Each site is previewed on its own and recorded as its own run, so a site that refuses is a row rather than a group that half happened.'">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.all', $group->ReconArea) }}">Run another</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('refusal'))
        <x-notice tone="stop" title="Nothing was posted" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    @if (session('posted'))
        @php($posted = collect(session('posted')))
        <x-notice :tone="$posted->every(fn ($r) => $r['Ok']) ? 'info' : 'warn'"
                  :title="$posted->where('Ok', true)->count().' of '.$posted->count().' '.Str::plural('site', $posted->count()).' posted'"
                  style="margin-bottom:16px">
            <p>Each site was committed on its own, through the same procedure a single run uses — every
               row re-read and re-checked against the estate as it stands now, not as it stood at
               preview time.</p>
            <ul>
                @foreach ($posted as $row)
                    <li>
                        <code>run #{{ $row['RunId'] }}</code> —
                        {{ $row['Ok'] ? \App\Support\Format::n($row['Rows']).' '.Str::plural('batch', $row['Rows']).' stamped' : $row['Code'] }}:
                        {{ $row['Message'] }}
                    </li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    <x-card :title="'Group '.Str::limit($group->GroupRef, 8, '')"
            :sub="'Previewed through '.$area['procedure'].' — the same procedure, the same arguments, once per site'"
            flush>
        <x-statstrip :stats="[
            ['label' => 'Sites', 'value' => \App\Support\Format::n($group->BranchCount), 'note' => 'in this group'],
            ['label' => 'Previewed', 'value' => \App\Support\Format::n($group->CompletedCount), 'note' => 'answered', 'tone' => 'good'],
            ['label' => 'Refused', 'value' => \App\Support\Format::n($group->FailedCount),
             'note' => 'no usable rule', 'tone' => $group->FailedCount > 0 ? 'serious' : 'neutral'],
            ['label' => 'Would reconcile', 'value' => \App\Support\Format::n($runs->sum('MatchedRows')),
             'note' => \App\Support\Format::r($runs->sum(fn ($r) => (float) $r->MatchedTotal)), 'tone' => 'good'],
            ['label' => 'Bank only', 'value' => \App\Support\Format::n($runs->sum('BankOnlyRows')),
             'note' => 'no deposit behind them', 'tone' => 'serious'],
            ['label' => 'Posted', 'value' => \App\Support\Format::n($group->CommittedRows),
             'note' => \App\Support\Format::r($group->CommittedTotal)],
        ]" />

        @if ($outstanding !== [])
            {{-- The driver. One small POST per site, in order, so the table
                 fills in as it goes and a refusal is a row rather than a dead
                 page. A reload picks up wherever it stopped, because the
                 outstanding list is recomputed from the runs on the server. --}}
            <div class="group-progress" data-recon-group
                 data-group-total="{{ $group->BranchCount }}"
                 data-group-done="{{ $group->CompletedCount + $group->FailedCount }}"
                 data-group-branches="{{ implode(',', $outstanding) }}"
                 data-group-url="{{ route('app.recon.group.branch', [$group->GroupRef, 0]) }}"
                 style="margin:12px 14px 0">
                <p class="field-help" data-group-status>
                    {{ count($outstanding) }} {{ Str::plural('site', count($outstanding)) }} still to preview.
                </p>
                <button type="button" class="btn-primary" data-group-start>Preview the remaining sites</button>
                <span class="field-help">One site at a time. You can leave this page and come back — the
                      group remembers what it has done.</span>
            </div>
        @endif

        <form method="POST" action="{{ route('app.recon.group.execute', $group->GroupRef) }}" id="group-post"
              data-confirm="{{ $stampMode === 'live' ? 'Reconcile the ticked sites in PumpIT?' : 'Record the ticked sites?' }}"
              data-confirm-text="{{ $stampMode === 'live'
                  ? 'Each ticked site is committed on its own, in its own transaction, through the same procedure a single run uses. Every row is re-checked first and anything that has moved since the preview is skipped. A site that refuses is reported and the others still go. Each one can be reversed from its own run page.'
                  : 'Journal mode: the decisions are recorded here and nothing in PumpIT changes.' }}"
              data-confirm-action="{{ $stampMode === 'live' ? 'Post the ticked sites' : 'Record' }}"
              @if ($stampMode === 'live') data-confirm-danger @endif>
            @csrf

            <x-table :count="$sites->count()" :procedure="'agora.'.$area['procedure']"
                     empty="No trading site is in scope for you.">
                <x-slot:head>
                    <tr>
                        <th class="pick"><input type="checkbox" data-check-all aria-label="Post every site that would reconcile"></th>
                        <th>Site</th>
                        <th>Result</th>
                        <th class="num">Proposals</th>
                        <th class="num">Would reconcile</th>
                        <th class="num">Value</th>
                        <th class="num">Bank only</th>
                        <th class="num">Deposit only</th>
                        <th class="num">Posted</th>
                    </tr>
                </x-slot:head>

                @foreach ($sites as $site)
                    @include('recon::partials.group-row', [
                        'branch' => $site,
                        'run' => $runs->get($site->BranchId),
                    ])
                @endforeach
            </x-table>

            <footer class="run-actions">
                @php($postable = $runs->filter(fn ($r) => $r->Status === 'previewed' && $r->MatchedRows > 0))
                <button type="submit" form="group-post" class="btn-primary" @disabled($postable->isEmpty())>
                    {{ $stampMode === 'live' ? 'Post' : 'Record' }}
                    {{ $postable->count() }} {{ Str::plural('site', $postable->count()) }}
                </button>
                <p class="field-help">
                    It posts the sites you ticked and no others — there is no "post everything" here on
                    purpose. Each site commits in its own transaction, so one refusal does not take the
                    others with it, and each one is reversed from its own run page.
                    @if ($group->FailedCount > 0)
                        <br><strong>{{ $group->FailedCount }} {{ Str::plural('site', $group->FailedCount) }}
                        could not run at all.</strong> That is a configuration answer, not an empty one —
                        the site has no usable <code>BRN_AutoReconCriteria</code> row, and no amount of
                        re-running will change it.
                    @endif
                </p>
            </footer>
        </form>
    </x-card>
</x-app-shell>
