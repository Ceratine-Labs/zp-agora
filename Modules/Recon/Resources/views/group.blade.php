<x-app-shell :title="$area['label'].' — every site'">
    {{-- Facts in the blurb; how the group runs and posts is behind the info
         button (4 Oct 2026). --}}
    <x-page-head
        eyebrow="Auto reconciliation"
        :title="($group->Note ?: $area['label']).' — every site'"
        :blurb="$group->BranchCount.' trading '.Str::plural('site', $group->BranchCount)
                .' · '.$group->FromDate->format('j M Y').' to '.$group->ToDate->format('j M Y')">
        <x-slot:info>
            <p>Each site is previewed on its own through <code>agora.{{ $area['procedure'] }}</code>, with the
               same arguments, and recorded as its own run: a site that refuses is a row, not a group that half
               happened.</p>
            <p>One site at a time, about two seconds each. You can leave and come back — the group remembers what
               it has done.</p>
            <p>A site that <strong>refused</strong> has no usable <code>BRN_AutoReconCriteria</code> row: a
               configuration answer, and re-running will not change it.</p>
        </x-slot:info>
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

    <x-card :title="'Group '.Str::limit($group->GroupRef, 8, '')" flush>
        <x-statstrip :stats="[
            ['label' => 'Sites', 'value' => \App\Support\Format::n($group->BranchCount)],
            ['label' => 'Previewed', 'value' => \App\Support\Format::n($group->CompletedCount), 'tone' => 'good'],
            ['label' => 'Refused', 'value' => \App\Support\Format::n($group->FailedCount),
             'tone' => $group->FailedCount > 0 ? 'serious' : 'neutral'],
            ['label' => 'Would reconcile', 'value' => \App\Support\Format::n($runs->sum('MatchedRows')),
             'note' => \App\Support\Format::r($runs->sum(fn ($r) => (float) $r->MatchedTotal)), 'tone' => 'good'],
            ['label' => 'Bank only', 'value' => \App\Support\Format::n($runs->sum('BankOnlyRows')),
             'tone' => 'serious'],
            ['label' => 'Posted', 'value' => \App\Support\Format::n($group->CommittedRows),
             'note' => \App\Support\Format::r($group->CommittedTotal)],
        ]" />

        @include('recon::partials.unconfigured-sites', [
            'unconfigured' => $unconfigured,
            'area' => $area,
            'noticeStyle' => 'margin:12px 14px 0',
        ])

        @if ($outstanding !== [])
            {{-- The driver. One small POST per site, in order, so the table
                 fills in as it goes and a refusal is a row rather than a dead
                 page. A reload picks up wherever it stopped, because the
                 outstanding list is recomputed from the runs on the server. --}}
            <div data-recon-group
                 data-group-total="{{ $group->BranchCount }}"
                 data-group-done="{{ $group->CompletedCount + $group->FailedCount }}"
                 data-group-branches="{{ implode(',', $outstanding) }}"
                 {{-- Nothing previewed yet means this is the page the press
                      landed on, so it goes on its own; a half-done group waits
                      for the button, because resuming is a decision. --}}
                 data-group-auto="{{ $group->CompletedCount + $group->FailedCount === 0 ? '1' : '0' }}"
                 data-group-url="{{ route('app.recon.group.branch', [$group->GroupRef, 0]) }}"
                 style="margin:12px 14px 0">
                <p class="field-help" data-group-status>
                    @if ($group->CompletedCount + $group->FailedCount === 0)
                        Starting — {{ count($outstanding) }} {{ Str::plural('site', count($outstanding)) }} to preview, one at a time.
                    @else
                        {{ count($outstanding) }} {{ Str::plural('site', count($outstanding)) }} still to preview.
                    @endif
                </p>
                <button type="button" class="btn-primary" data-group-start>
                    {{ $group->CompletedCount + $group->FailedCount === 0 ? 'Start again' : 'Preview the remaining sites' }}
                </button>
            </div>
        @endif

        @php($postsActive = $posts->filter(fn ($p) => $p->isActive())->count())
        @if ($postsActive > 0)
            {{-- The posts. The press queued one job per site (12 Nov 2026);
                 this polls agora.ReconGroupPost and replaces each row as its
                 site answers, then reloads for the totals. Leaving the page
                 stops nothing — the worker posts whether anyone watches. --}}
            <div data-recon-group-posts
                 data-posts-url="{{ route('app.recon.group.posts', $group->GroupRef) }}"
                 data-posts-active="{{ $postsActive }}"
                 style="margin:12px 14px 0">
                <p class="field-help" data-posts-status>
                    Posting {{ $postsActive }} {{ Str::plural('site', $postsActive) }} in the background, one at a
                    time — you can leave this page.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('app.recon.group.execute', $group->GroupRef) }}" id="group-post"
              data-confirm-filtered="group-sites"
              data-confirm="{{ $stampMode === 'live' ? 'Reconcile the ticked sites in PumpIT?' : 'Record the ticked sites?' }}"
              data-confirm-text="{{ $stampMode === 'live'
                  ? 'Each ticked site is queued and committed on its own, in its own transaction, through the same procedure a single run uses. Every row is re-checked first and anything that has moved since the preview is skipped. A site that refuses is reported and the others still go. Each one can be reversed from its own run page.'
                  : 'Journal mode: the decisions are recorded here and nothing in PumpIT changes.' }}"
              data-confirm-action="{{ $stampMode === 'live' ? 'Post the ticked sites' : 'Record' }}"
              @if ($stampMode === 'live') data-confirm-danger @endif>
            @csrf

            @php($postable = $runs->filter(fn ($r) => $r->Status === 'previewed' && $r->MatchedRows > 0
                && ! ($posts->get($r->BranchId)?->isActive() ?? false)))

            {{-- The press, above the sites it posts, and pinned there. Twenty-six
                 rows is not four hundred, but the button was still under the
                 table and under a paragraph, and this is one decision for the
                 whole application rather than a judgement per screen. --}}
            <x-action-bar for="group-sites">
                <button type="submit" form="group-post" class="btn-primary"
                        data-count-verb="{{ $stampMode === 'live' ? 'Post' : 'Record' }}"
                        data-count-noun="site" data-count-plural="sites"
                        @disabled($postable->isEmpty())>
                    {{ $stampMode === 'live' ? 'Post' : 'Record' }}
                    {{ $postable->count() }} {{ Str::plural('site', $postable->count()) }}
                </button>

                <x-info label="What this press does">
                    <p>Posts the ticked sites and no others. Each site is its own queued job and commits in its own
                       transaction, through the same procedure a single run uses, so one refusal or one slow site
                       does not take the others with it. The rows fill in as the sites answer.</p>
                    @if ($stampMode === 'live')
                        <p>Every row is re-checked against PumpIT first and anything that has moved since the preview
                           is skipped. Each site is reversed from its own run page.</p>
                    @else
                        <p><strong>Journal mode:</strong> recorded in Agora only; nothing in PumpIT changes.</p>
                    @endif
                </x-info>
            </x-action-bar>

            <x-table layout-key="Recon.group.1" :count="$sites->count()" :procedure="'agora.'.$area['procedure']"
                     id="group-sites" tools
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
                        'post' => $posts->get($site->BranchId),
                    ])
                @endforeach
            </x-table>

        </form>
    </x-card>
</x-app-shell>
