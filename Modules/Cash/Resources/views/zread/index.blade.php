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

    The standing explanation — what allocating means, how the system
    proposes, and what Update ZREAD writes to PumpIT — sits behind the info
    button beside the title (ZRP, 4 Oct 2026). Every press that writes PumpIT
    says so in its own confirmation.
--}}
@php
    $canAllocate = (bool) auth()->user()?->can('cash.zread.allocate');
    $canCommit = (bool) auth()->user()?->can('cash.zread.commit');
    $canReverse = (bool) auth()->user()?->can('cash.zread.reverse');
    $live = $stampMode === 'live';
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
@endphp

<x-app-shell title="ZREAD Allocation">
    <x-page-head eyebrow="Branch functions · Cash" title="ZREAD Allocation">
        <x-slot:info>
            <p>A Z-read arrives from the till with a number and an operator ID, nothing else. Allocating it says which
               till, which shift and who was on it. The system proposes from the site's last
               {{ config('cash.zread.history_days') }} days of allocations, made here and on PumpIT's ZREAD Update
               screen — let it propose, and only touch the ones it cannot call.</p>
            <p><strong>Certain</strong>: this terminal and operator ID carried the same till, shift and person on at
               least {{ (int) round(config('cash.zread.certain_share') * 100) }}% of prior Z-reads.
               <strong>Likely</strong>: the operator ID was the same person on at least
               {{ (int) round(config('cash.zread.likely_share') * 100) }}%, and the shift only when that operator ID runs
               one shift at least {{ (int) round(config('cash.zread.shift_share') * 100) }}% of the time.
               <strong>Review</strong>: no settled person, but somebody usually works this terminal on that shift.
               <strong>Needs a person</strong>: nothing to go on, usually an operator ID the site has never
               allocated.</p>
            <p>Auto-allocate and the row buttons only <em>stage</em>; Update ZREAD saves the staged rows in Agora,
               keeping what each carried before.
               @if ($live)
                   <strong>Update ZREAD writes to PumpIT</strong>: TillNo, ShiftNo and EmployeeCode on each Z-read's own
                   row (<code>DBF_P3TRANS_ZREAD</code>), nothing else, recording what the row held first. Reverse puts
                   both sides back. A Z-read allocated again on the PumpIT screen afterwards shows that allocation,
                   marked "changed in PumpIT", and its press can no longer be reversed.
               @else
                   <strong>The PumpIT stamp is off</strong>: PumpIT's Z-read rows are not written, so its ZREAD Update
                   screen still shows its own allocations. Reverse undoes a press here.
               @endif
            </p>
        </x-slot:info>
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

    <x-card title="Which Z-reads">
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

    {{-- What Update ZREAD writes to PumpIT is in the page's info panel (ZRP,
         4 Oct 2026); before that it stood open here. The press still says it
         in its own confirmation, before anything is written. --}}

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
                 'note' => $summary['staged'] > 0 ? 'press Update ZREAD' : null],
                ['label' => 'Proposals waiting', 'value' => \App\Support\Format::n($summary['certain'] + $summary['likely'] + $summary['review']),
                 'note' => $summary['certain'].' certain · '.$summary['likely'].' likely · '.$summary['review'].' review'],
                ['label' => 'Needs a person', 'value' => \App\Support\Format::n($summary['manual']),
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
            <x-card title="Saved presses" flush style="margin-top:16px">
                <x-slot:info>
                    <p>Every Update ZREAD that saved a Z-read in these days, newest first. This is the only place a
                       press can be undone: Reverse puts back what it replaced, and is refused if any of its Z-reads
                       has been allocated again since.</p>
                </x-slot:info>
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

    @endif
</x-app-shell>
