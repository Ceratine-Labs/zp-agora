{{--
    ZREAD Allocation (T036) — the mockup's #/op/zread, on real Z-reads.

    Scope (site, period, location, show) → run bar (Auto-allocate / Clear
    proposals / Update ZREAD) → the figures → the grid, where the Decision
    column is the only colour and a row opens onto its proposal, its evidence
    and the forms that act on it.

    The three presses are three forms with three permissions. The run bar sits
    in the Auto-allocate form; Clear and Update ZREAD are buttons that submit
    their OWN forms by id (the `form` attribute), so each keeps its own route,
    its own `can:` and its own confirmation — a formaction on a shared form
    would lose the confirmation, because confirm-form.js re-submits the form
    itself and a submitter's formaction does not survive that.
--}}
@php
    $canAllocate = (bool) auth()->user()?->can('cash.zread.allocate');
    $canCommit = (bool) auth()->user()?->can('cash.zread.commit');
    $canReverse = (bool) auth()->user()?->can('cash.zread.reverse');
    $live = $stampMode === 'live';
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
@endphp

<x-app-shell title="ZREAD Allocation">
    <x-page-head
        eyebrow="Branch functions · Cash"
        title="ZREAD Allocation"
        blurb="A Z-read arrives from the till with a number and an operator ID, and nothing else. Allocating it means saying which till, which shift and who was on it. The system already knows what this site normally does — let it propose, and only touch the ones it cannot call.">
        @if ($scope->isChosen())
            <x-slot:actions>
                <a class="btn-ghost" href="{{ route('app.cash.zread.confirm', $scope->query()) }}">On a phone: confirm only</a>
            </x-slot:actions>
        @endif
    </x-page-head>

    @if (session('zreadRefusal'))
        <x-notice tone="stop" title="That was not done" style="margin-bottom:16px">
            <p>{{ session('zreadRefusal') }}</p>
        </x-notice>
    @elseif (session('zreadStatus'))
        <x-notice tone="info" style="margin-bottom:16px">
            <p>{{ session('zreadStatus') }}</p>
        </x-notice>
    @endif

    <x-card title="Which Z-reads" sub="One site at a time — a Z-read is proposed from what that site has done before.">
        <form method="GET" action="{{ route('app.cash.zread') }}">
            <x-params legend="Scope">
                @if ($pinned)
                    <x-param label="Site">
                        <p class="field-help">{{ $site ?? 'Your workspace site' }} — your workspace is pinned to it.</p>
                    </x-param>
                @else
                    <x-param name="branch_id" label="Site" searchable
                             :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                             :value="$scope->branchId" />
                @endif
                <x-param name="date_from" label="Date from" type="date" :value="$scope->from" />
                <x-param name="date_to" label="Date to" type="date" :value="$scope->to" />
                <x-param name="location" label="Location"
                         :choices="['' => 'Every location'] + array_combine($locations, $locations)"
                         :value="$scope->location" />
                <x-param name="show" label="Show"
                         :choices="['open' => 'What is waiting', 'all' => 'Allocated ones too']"
                         :value="$scope->show" />
            </x-params>
            <div class="form-actions">
                <button type="submit" class="btn">Show Z-reads</button>
            </div>
        </form>
    </x-card>

    {{-- What Update ZREAD does to the customer's database, said before the
         press rather than found out after it. Open, not folded: it is about
         every press on this screen, not standing explanation. --}}
    @if ($live)
        <x-notice tone="warn" title="Update ZREAD writes to PumpIT" style="margin-bottom:16px">
            <p>Saving a Z-read also writes its till, shift and person onto the Z-read's own row in PumpIT
               (<code>DBF_P3TRANS_ZREAD</code>: TillNo, ShiftNo, EmployeeCode — nothing else), so the PumpIT cashup
               and stock screens see it. What the row held before is recorded first, and Reverse on a saved press
               puts it back.</p>
            <p>If somebody allocates the same Z-read again on the PumpIT screen afterwards, theirs is the later
               decision and it is the one shown here, marked "changed in PumpIT". That press can then no longer be
               reversed, because reversing it would write over their allocation.</p>
        </x-notice>
    @else
        <x-notice tone="info" title="The PumpIT stamp is off" style="margin-bottom:16px">
            <p>Update ZREAD saves allocations in Agora only. PumpIT's Z-read rows are not written, so the PumpIT
               ZREAD Update screen still shows its own allocations.</p>
        </x-notice>
    @endif

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Pick the site and the days, and the Z-reads waiting to be allocated appear here with what the system proposes for each." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The Z-reads could not be read">
            <p>{{ $refusal }}</p>
        </x-notice>
    @else
        <x-card flush>
            @if ($canAllocate)
                <form id="zread-auto" method="POST" action="{{ route('app.cash.zread.auto') }}">
                    @csrf
                    @include('cash::zread.partials.scope-inputs')
                    <x-runbar action="Auto-allocate" busy="Proposing…" procedure="agora.usp_Cash_AutoAllocate"
                              :status="'Auto-allocate stages what the system is '.$threshold.' of. Nothing is allocated until Update ZREAD.'">
                        <button type="submit" form="zread-clear" class="btn">Clear proposals</button>
                        @if ($canCommit)
                            <button type="submit" form="zread-commit" class="btn">Update ZREAD</button>
                        @endif
                    </x-runbar>
                </form>
                <form id="zread-clear" method="POST" action="{{ route('app.cash.zread.clear') }}"
                      data-confirm="Clear the staged proposals?"
                      data-confirm-text="Everything staged for {{ $site }} from {{ $scope->from }} to {{ $scope->to }} and not yet saved is set aside. Nothing already allocated is touched."
                      data-confirm-action="Clear proposals">
                    @csrf
                    @include('cash::zread.partials.scope-inputs')
                </form>
            @endif
            @if ($canCommit)
                <form id="zread-commit" method="POST" action="{{ route('app.cash.zread.commit') }}"
                      data-confirm="Save {{ $summary['staged'] }} staged {{ \Illuminate\Support\Str::plural('allocation', $summary['staged']) }}?"
                      data-confirm-text="{{ $live
                          ? 'Each staged Z-read becomes allocated to its till, shift and person, in Agora AND on its PumpIT row (TillNo, ShiftNo, EmployeeCode). What PumpIT held before is recorded, so the press can be reversed. An earlier allocation is kept as superseded. A Z-read that does not name exactly one PumpIT row is skipped.'
                          : 'Each staged Z-read becomes allocated to its till, shift and person, in Agora. An earlier allocation is kept as superseded. The PumpIT stamp is off, so nothing is written to PumpIT.' }}"
                      data-confirm-action="Update ZREAD" data-loader="Saving…">
                    @csrf
                    @include('cash::zread.partials.scope-inputs')
                    @unless ($canAllocate)
                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Update ZREAD</button>
                        </div>
                    @endunless
                </form>
            @endif

            <x-statstrip :stats="[
                ['label' => 'Z-reads in the period', 'value' => \App\Support\Format::n($summary['total']),
                 'note' => $scope->location ?? 'every location'],
                ['label' => 'Not allocated', 'value' => \App\Support\Format::n($summary['waiting']),
                 'note' => \App\Support\Format::r($summary['waitingSales']).' unclaimed',
                 'tone' => $summary['waiting'] > 0 ? 'warn' : 'good'],
                ['label' => 'Staged, not saved', 'value' => \App\Support\Format::n($summary['staged']),
                 'note' => $summary['staged'] > 0 ? 'press Update ZREAD' : 'run Auto-allocate'],
                ['label' => 'Proposals waiting', 'value' => \App\Support\Format::n($summary['certain'] + $summary['likely'] + $summary['review']),
                 'note' => $summary['certain'].' certain · '.$summary['likely'].' likely · '.$summary['review'].' review'],
                ['label' => 'Needs a person', 'value' => \App\Support\Format::n($summary['manual']),
                 'note' => 'no history to go on',
                 'tone' => $summary['manual'] > 0 ? 'serious' : 'neutral'],
            ]" />
        </x-card>

        <x-data-grid :grid="$grid" surface />

        @if ($commits->isNotEmpty())
            {{-- The presses that saved Z-reads in these days, and the only place
                 a press can be undone. Times are stored in UTC like every Agora
                 timestamp and shown relative, as the recon screens show theirs,
                 so nobody has to know the server's offset; the exact UTC instant
                 is on hover. --}}
            <x-card title="Saved presses" sub="Every Update ZREAD that saved a Z-read in these days, newest first." flush style="margin-top:16px">
                {{-- Three narrow columns, and what a press SAYS or lets you DO on a
                     full-width line beneath it. On a phone the table then fits
                     the screen, so the state and the Reverse form are never
                     behind a sideways scroll; on a desk it reads as a list. --}}
                <x-table :count="$commits->count()" procedure="agora.usp_Cash_ZreadCommits" dense>
                    <x-slot:head>
                        <tr>
                            <th>Saved</th>
                            <th class="num">Z-reads</th>
                            <th>State</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($commits as $c)
                        @php($at = $c->CommittedAt ? \Illuminate\Support\Carbon::parse($c->CommittedAt, 'UTC') : null)
                        @php($note = match (true) {
                            $c->State === 'reversed' => ($c->ReversedAt ? 'Reversed '.\Illuminate\Support\Carbon::parse($c->ReversedAt, 'UTC')->diffForHumans() : 'Reversed').' — '.$c->ReversalReason,
                            (int) $c->ChangedInPumpIt > 0 => $c->ChangedInPumpIt.' of its Z-reads allocated again on the PumpIT screen since — that allocation wins, and this press can no longer be reversed.',
                            $c->State === 'partly replaced' => 'A later press re-allocated some of its Z-reads — reverse that one first.',
                            default => null,
                        })
                        @php($reversible = $canReverse && $c->CanReverse)
                        <tr>
                            <td title="{{ $at?->toIso8601String() }}">
                                {{ $at?->diffForHumans() ?? \App\Support\Format::NOTHING }}
                                <br><span class="muted">by {{ $c->CommittedBy ?? \App\Support\Format::NOTHING }}</span>
                                <br><span class="muted mono">{{ $c->FirstDate === $c->LastDate ? $c->FirstDate : $c->FirstDate.' – '.$c->LastDate }}</span>
                            </td>
                            <td class="num">{{ \App\Support\Format::n($c->Zreads) }}</td>
                            <td>
                                <x-chip :tone="match ($c->State) { 'in force' => 'good', 'reversed' => 'neutral', 'changed in PumpIT' => 'serious', default => 'warn' }">{{ $c->State }}</x-chip>
                                <br><span class="muted">PumpIT {{ $c->StampMode === 'live' ? 'written' : 'not written' }}</span>
                            </td>
                        </tr>
                        @if ($note !== null || $reversible)
                            <tr>
                                <td colspan="3">
                                    @if ($note !== null)
                                        <span class="muted">{{ $note }}</span>
                                    @endif
                                    @if ($reversible)
                                        {{-- Capped, so on a desk the reason box and the button do
                                             not stretch across the whole card. --}}
                                        <form method="POST" action="{{ route('app.cash.zread.reverse') }}" class="inline-reason" style="max-width:360px"
                                              data-confirm="Reverse this press?"
                                              data-confirm-text="{{ $c->StampMode === 'live'
                                                  ? 'Its '.$c->Zreads.' allocation(s) are marked reversed, what they replaced is back in force, and each PumpIT row gets back the till, shift and person it held before. Refused if any of them has been allocated again since.'
                                                  : 'Its '.$c->Zreads.' allocation(s) are marked reversed and what they replaced is back in force. This press did not write PumpIT, so nothing there moves.' }}"
                                              data-confirm-action="Reverse" data-confirm-danger>
                                            @csrf
                                            @include('cash::zread.partials.scope-inputs')
                                            <input type="hidden" name="commit_ref" value="{{ $c->CommitRef }}">
                                            <input type="text" name="reason" required maxlength="300" placeholder="Why it is being reversed" aria-label="Reason for reversing">
                                            <button type="submit" class="btn sm">Reverse</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </x-table>
            </x-card>
        @endif

        <x-explainer summary="How the system proposes, and what Update ZREAD writes" remember="cash-zread">
            <x-notice tone="info" title="The proposal">
                <p><strong>Certain</strong> — this terminal and this operator ID have carried the same till, shift
                   and person on at least {{ (int) round(config('cash.zread.certain_share') * 100) }}% of the site's
                   prior Z-reads. <strong>Likely</strong> — the operator ID has been the same person on at least
                   {{ (int) round(config('cash.zread.likely_share') * 100) }}%; the shift is proposed only when the
                   operator ID runs one shift at least {{ (int) round(config('cash.zread.shift_share') * 100) }}% of
                   the time, and is otherwise left to you. <strong>Review</strong> — no settled person, but somebody
                   usually works this terminal on that shift. <strong>Needs a person</strong> — nothing to go on,
                   which usually means an operator ID the site has never allocated.</p>
                <p>The history is the last {{ config('cash.zread.history_days') }} days at this site, from the
                   allocations made here and on the PumpIT ZREAD Update screen.</p>
            </x-notice>
            <x-notice tone="info" title="What is written">
                <p>Auto-allocate and the row buttons only <em>stage</em>. Update ZREAD saves the staged rows as the
                   Z-reads' allocations in Agora's own ledger, keeping what each carried before.
                   @if ($live)
                       It also writes TillNo, ShiftNo and EmployeeCode onto each Z-read's PumpIT row, recording what the
                       row held first, and nothing else — unlike the PumpIT screen it does not touch the stock recon
                       lines or the computer quantities. Reverse, on a saved press, puts both sides back. A Z-read
                       somebody allocates again on the PumpIT screen afterwards shows their allocation, marked
                       "changed in PumpIT", and its press can no longer be reversed.
                   @else
                       The PumpIT stamp is off, so PumpIT's rows are not written and its ZREAD Update screen still shows
                       its own allocations. Reverse, on a saved press, undoes it here.
                   @endif
                </p>
                <p>The legacy screen colours its rows cyan, yellow and magenta with no key anywhere on the form, and
                   every allocation is typed by hand. Here the only colour left is the one that carries a decision.</p>
            </x-notice>
        </x-explainer>
    @endif
</x-app-shell>
