{{--
    Daily Banking (T037) — the mockup's #/op/banking, on real cashups.

    "Cashups by shift and till, Z read against what was actually declared."

    Scope (site, day) → the day's cashups, with the figures above them and the
    Z-reads nobody has cashed up yet → one cashup opened: its figures, its legs
    as tabs, the card load, Confirm and Close, and what has been done to it.

    Every figure is agora.usp_Cash_GetCashup's. The Z read, the actual total and
    the variance are exactly what PumpIT's own Daily Banking grid
    (dbo.sp_GetDailyBankingWithMOPS) shows for the same cashup.

    WHAT REACHES PUMPIT: Save card amounts, and nothing else on this screen —
    the FNB leg (BRN_DailyBankingFNB) and the cashup's header
    (BRN_DailyBanking), while cash.cashup.write_mode is 'live'. The other legs
    are read here and captured in PumpIT until Ryan decides how their "banked"
    marks are kept (see the procedure's header). Confirm and Close write
    Agora's database only.

    The standing explanation — how the figures are worked out, what Confirm
    and Close mean, and what a save writes to PumpIT — sits behind the info
    button beside the title (ZRP, 4 Oct 2026: users skipped every paragraph).
    Refusals, statuses and the counts of THIS day stay on the page.

    Props: $scope, $branches, $pinned, $refusal, $cashups, $zreads, $one, $lines,
           $events, $cards, $summary, $channels, $writeMode, $savable, $tolerance
--}}
@php
    $service = app(\Modules\Cash\Services\CashupService::class);
    $canSave = (bool) auth()->user()?->can('cash.cashup.save');
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
    $notCaptured = $zreads->filter(fn ($z) => $z->IsAllocated && ! $z->HasCashup && (float) $z->Sales !== 0.0);
    $unallocated = $zreads->filter(fn ($z) => ! $z->IsAllocated);
    $selectedZread = $scope->isOne()
        ? $zreads->first(fn ($z) => (int) $z->TillNo === $scope->tillNo && (int) $z->ShiftNo === $scope->shiftNo && $z->IsAllocated)
        : null;
    $cardSavable = $writeMode === 'live' && in_array('FNB', $savable, true);
@endphp

<x-app-shell title="Daily Banking">
    <x-page-head eyebrow="Branch functions · Cash" title="Daily Banking">
        <x-slot:info>
            <p>Each cashup's <strong>Z read</strong> (what the till took) against its <strong>actual total</strong>
               (every leg, added the way PumpIT's Daily Banking adds them). <strong>Variance</strong> is actual less
               Z read. A line whose customer, supplier or person is not on PumpIT's list is not counted, as in
               PumpIT, and is marked.</p>
            <p><strong>Z-read</strong> says whether the Z-read allocated to the till and shift agrees with the
               cashup. Confirming one that does not, or whose variance is beyond R{{ \App\Support\Format::n($tolerance) }}
               either way, needs a reason. <strong>Confirm</strong> agrees to the figures; <strong>Close</strong> is a
               manager's sign-off, once the confirmed figures have not moved; a manager can <strong>reopen</strong>
               with a reason. All three are recorded in Agora only.</p>
            @if ($cardSavable)
                <p><strong>Saving a leg writes to PumpIT.</strong> "Load card amounts" and "Add from PumpIT" write the
                   lines and bring the cashup's totals into line, creating the cashup from its Z-read if needed. A row
                   added from a pool is marked banked there, so it cannot be banked twice. Prior values are recorded,
                   a reconciled line is never changed, nothing is deleted. Fleet cards and direct deposits are captured
                   in PumpIT; their tabs say why.</p>
            @else
                <p><strong>Saving to PumpIT is off.</strong> This screen reads PumpIT's cashups; nothing is written
                   there.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            @if ($scope->isChosen())
                <a class="btn-ghost" href="{{ route('app.cash.banking.phone', $scope->withCashup(null, null)->query()) }}">On a phone: read and confirm</a>
            @endif
            <a class="btn-ghost" href="{{ route('app.cash.cashups') }}">Cashups — manager's view</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('cashupRefusal'))
        <x-notice tone="stop" title="That was not done" style="margin-bottom:16px">
            <p>{{ session('cashupRefusal') }}</p>
        </x-notice>
    @elseif (session('cashupStatus'))
        <x-notice tone="info" style="margin-bottom:16px">
            <p>{{ session('cashupStatus') }}</p>
        </x-notice>
    @endif

    <x-card title="Which day">
        <form method="GET" action="{{ route('app.cash.banking') }}">
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
                <x-param name="date" label="Trading day" type="date" :value="$scope->date" />
            </x-params>
            <div class="form-actions">
                <button type="submit" class="btn">Show the day</button>
            </div>
        </form>
    </x-card>

    {{-- What a save writes to PumpIT is in the page's info panel; each press
         that writes says so again in its own confirmation. --}}

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Pick the site and the day, and its cashups appear here — each with its Z read, what was declared and the variance." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The cashups could not be read">
            <p>{{ $refusal }}</p>
        </x-notice>
    @else
        <x-card :title="($site ?? 'The site').' · '.\Illuminate\Support\Carbon::parse($scope->date)->format('j M Y')" flush>
            <x-statstrip :stats="[
                ['label' => 'Cashups', 'value' => \App\Support\Format::n($summary['cashups']),
                 'note' => $notCaptured->count() > 0 ? $notCaptured->count().' Z-read(s) not cashed up' : 'every allocated Z-read cashed up',
                 'tone' => $notCaptured->count() > 0 ? 'warn' : 'neutral'],
                ['label' => 'Net variance', 'value' => \App\Support\Format::r($summary['variance']),
                 'tone' => $service->varianceTone($summary['variance'])],
                ['label' => 'Beyond ±R'.\App\Support\Format::n($tolerance), 'value' => \App\Support\Format::n($summary['beyond']),
                 'tone' => $summary['beyond'] > 0 ? 'crit' : 'good'],
                ['label' => 'To confirm', 'value' => \App\Support\Format::n($summary['toConfirm']),
                 'note' => $summary['toClose'].' waiting on a manager', 'tone' => $summary['toConfirm'] > 0 ? 'warn' : 'good'],
                ['label' => 'Z-read disagrees', 'value' => \App\Support\Format::n($summary['disagree']),
                 'tone' => $summary['disagree'] > 0 ? 'serious' : 'good'],
                ['label' => 'Pump shortages', 'value' => \App\Support\Format::r($summary['shorts'])],
            ]" />

            <x-table :count="$cashups->count()" procedure="agora.usp_Cash_GetCashup" dense
                     empty="PumpIT holds no cashups for this site on this day.">
                <x-slot:head>
                    <tr>
                        <th>Till</th>
                        <th>Shift</th>
                        <th>Cashier</th>
                        <th class="num">Z read</th>
                        <th class="num">Actual total</th>
                        <th class="num">Variance</th>
                        <th>Z-read</th>
                        <th>Cashup</th>
                    </tr>
                </x-slot:head>
                @foreach ($cashups as $c)
                    @php($open = $scope->isOne() && (int) $c->TillNo === $scope->tillNo && (int) $c->ShiftNo === $scope->shiftNo)
                    <tr @if ($open) aria-current="true" @endif>
                        <td><a href="{{ route('app.cash.banking', $scope->withCashup((int) $c->TillNo, (int) $c->ShiftNo)->query()) }}">{{ $c->TillDescription ?? 'Till '.$c->TillNo }}</a></td>
                        <td>{{ $c->ShiftDescription ?? $c->ShiftNo }}</td>
                        <td>{{ $c->EmployeeName ?? $c->EmployeeCode }}</td>
                        <td class="num">{{ \App\Support\Format::r($c->ZRead) }}</td>
                        <td class="num">{{ \App\Support\Format::r($c->ActualTotal) }}</td>
                        <td class="num"><x-chip :tone="$service->varianceTone($c->Variance)" :dot="false">{{ \App\Support\Format::r($c->Variance) }}</x-chip></td>
                        <td><x-chip :tone="\Modules\Cash\Services\CashupService::zreadTone($c->ZreadCheck)">{{ $c->ZreadCheck }}</x-chip></td>
                        <td><x-chip :tone="\Modules\Cash\Services\CashupService::statusTone($c->Status)">{{ $c->Status }}</x-chip></td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>

        @if ($notCaptured->isNotEmpty() || $unallocated->isNotEmpty())
            <x-card title="Z-reads with no cashup" flush style="margin-top:16px">
                @if ($unallocated->isNotEmpty())
                    <x-notice tone="warn" style="margin:12px">
                        <p>{{ $unallocated->count() }} {{ \Illuminate\Support\Str::plural('Z-read', $unallocated->count()) }} that day {{ $unallocated->count() === 1 ? 'is' : 'are' }} not allocated to a till and shift yet —
                           <a href="{{ route('app.cash.zread', ['branch_id' => $scope->branchId, 'date_from' => $scope->date, 'date_to' => $scope->date]) }}">allocate {{ $unallocated->count() === 1 ? 'it' : 'them' }}</a> first.</p>
                    </x-notice>
                @endif
                <x-table :count="$notCaptured->count()" procedure="agora.usp_Cash_GetCashup" dense
                         empty="Every allocated Z-read that day has a cashup.">
                    <x-slot:head>
                        <tr>
                            <th>Till</th>
                            <th>Shift</th>
                            <th>Cashier</th>
                            <th>Z-read</th>
                            <th class="num">Sales</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($notCaptured as $z)
                        <tr>
                            <td><a href="{{ route('app.cash.banking', $scope->withCashup((int) $z->TillNo, (int) $z->ShiftNo)->query()) }}">{{ $z->TillDescription ?? 'Till '.$z->TillNo }}</a></td>
                            <td>{{ $z->ShiftDescription ?? $z->ShiftNo }}</td>
                            <td>{{ $z->EmployeeName ?? $z->EmployeeCode }}</td>
                            <td class="mono">{{ $z->Location }} · {{ $z->TerminalNo }} · EOD {{ $z->EodCounter }}</td>
                            <td class="num">{{ \App\Support\Format::r($z->Sales) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @endif

        @if ($scope->isOne())
            @php($cashupTitle = collect([
                $one?->TillDescription ?? $selectedZread?->TillDescription ?? 'Till '.$scope->tillNo,
                $one?->ShiftDescription ?? $selectedZread?->ShiftDescription ?? 'shift '.$scope->shiftNo,
                $one?->EmployeeName ?? $selectedZread?->EmployeeName ?? $one?->EmployeeCode,
            ])->filter()->implode(' · '))

            <x-card :title="$cashupTitle" style="margin-top:16px">
                @if ($one === null)
                    <x-empty-state title="No cashup yet"
                                   :text="$selectedZread
                                       ? 'PumpIT has no cashup for this till and shift. Its Z-read ('.\App\Support\Format::r($selectedZread->Sales).', EOD '.$selectedZread->EodCounter.') is allocated — save a leg to start one, or capture it in PumpIT.'
                                       : 'PumpIT has no cashup for this till and shift, and no Z-read is allocated to it that day.'" />
                    @if ($selectedZread && $cardSavable && $canSave && $cards === null)
                        <div class="form-actions">
                            <a class="btn" href="{{ route('app.cash.banking', $scope->query() + ['cards' => 1]) }}">Load card amounts</a>
                        </div>
                        <p class="muted">Or add a leg from PumpIT below — the first save creates the cashup.</p>
                    @endif
                @else
                    @include('cash::banking.partials.facts', ['one' => $one, 'tolerance' => $tolerance])
                    @include('cash::banking.partials.actions', ['scope' => $scope, 'one' => $one, 'tolerance' => $tolerance, 'from' => 'banking'])
                    @if ($cards === null && $one->Status !== 'Closed')
                        <div class="form-actions">
                            <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->query() + ['cards' => 1]) }}">Load card amounts</a>
                        </div>
                    @endif
                @endif
            </x-card>

            @if ($cards !== null)
                @include('cash::banking.partials.cards', ['scope' => $scope, 'cards' => $cards, 'writeMode' => $writeMode, 'savable' => $savable, 'one' => $one])
            @endif

            @if ($pool !== null && $poolChannel !== null)
                @include('cash::banking.partials.pool', ['scope' => $scope, 'pool' => $pool, 'channel' => $poolChannel,
                    'poolName' => config('cash.cashup.pool_names.'.$poolChannel, $poolChannel), 'one' => $one])
            @endif

            @if ($one !== null || $selectedZread !== null)
                <x-card title="The legs" flush style="margin-top:16px">
                    <x-slot:info>
                        <p>What the cashier declared, leg by leg; the actual total adds these up. A line marked
                           <em>not counted</em> is left out of the total, as PumpIT leaves it out.</p>
                        <p>Each tab offers a way to add its lines, or says why that leg is captured in PumpIT.</p>
                    </x-slot:info>
                    @include('cash::banking.partials.legs', ['lines' => $lines, 'channels' => $channels, 'scope' => $scope,
                        'one' => $one, 'savable' => $savable, 'held' => $held])
                </x-card>
            @endif

            @if ($events->isNotEmpty())
                <x-card title="What has been done" flush style="margin-top:16px">
                    <x-table :count="$events->count()" procedure="agora.usp_Cash_GetCashup" dense>
                        <x-slot:head>
                            <tr>
                                <th>When</th>
                                <th>What</th>
                                <th class="num">Variance</th>
                                <th>Note</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($events as $e)
                            @php($at = $e->CreatedAt ? \Illuminate\Support\Carbon::parse($e->CreatedAt, 'UTC') : null)
                            <tr>
                                <td title="{{ $at?->toIso8601String() }}">{{ $at?->diffForHumans() ?? \App\Support\Format::NOTHING }}<br><span class="muted">by {{ $e->CreatedByName ?? \App\Support\Format::NOTHING }}</span></td>
                                <td>
                                    {{ ucfirst($e->Event) }}
                                    @if ($e->Channel) · {{ $e->Channel }} ({{ $e->Lines }} {{ \Illuminate\Support\Str::plural('line', (int) $e->Lines) }})@endif
                                    @if ($e->WriteMode === 'live')<br><span class="muted">written to PumpIT</span>@endif
                                </td>
                                <td class="num">{{ \App\Support\Format::r($e->Variance) }}</td>
                                <td>{{ $e->Note ?? \App\Support\Format::NOTHING }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                </x-card>
            @endif
        @endif
    @endif
</x-app-shell>
