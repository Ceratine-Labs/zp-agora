{{--
    Till balance (T038) — PumpIT's till-balance capture, on real cashups.

    A till balance moves part of one cashup's variance onto another cashup at
    the same site: a short on one till, shift or day is set against an over on
    another. PumpIT keeps it as two BRN_TillBalance rows under one number, and
    its approver's pass posts each to its cashup's Expenses leg ('Till Balance
    Expense') — that is when the two variances move.

    Scope (site, period) → the site's cashups with a variance worth moving,
    each with what the till balances waiting for approval will do to it → pick
    the cashup the amount comes off and the one it goes onto, give the amount,
    Capture → the till balances already captured, and where each stands.

    WHAT REACHES PUMPIT: Capture, and nothing else — the pair of BRN_TillBalance
    rows and the till balance counter (SS_UniqueNumber section 3), while
    cash.tillbalance.write_mode is 'live'. Approving and posting stay in PumpIT.

    Desktop, responsive: a till balance is captured in the site office, not on
    the forecourt (every capture of 2026 on the customer's instance was by a
    site's office login or head office).

    Props: $scope, $branches, $pinned, $refusal, $cashups, $balances, $summary,
           $writeMode, $floor
--}}
@php
    $canSave = (bool) auth()->user()?->can('cash.tillbalance.save');
    $live = $writeMode === 'live';
    $capture = $live && $canSave;
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
    $cashupKey = fn (object $c) => substr((string) $c->TransactionDate, 0, 10).'|'.$c->TillNo.'|'.$c->ShiftNo;
    $tone = fn ($v) => $v === null ? 'neutral' : (abs((float) $v) < 1.0 ? 'good' : ((float) $v < 0 ? 'serious' : 'warn'));
    $side = fn (?string $date, $till, $shift, ?string $person) => $date === null ? \App\Support\Format::NOTHING
        : \Illuminate\Support\Carbon::parse($date)->format('j M').' · '.($till ?? '?').' · '.($shift ?? '?').($person ? ' · '.$person : '');
@endphp

<x-app-shell title="Till balance">
    <x-page-head
        eyebrow="Branch functions · Cash"
        title="Till balance"
        blurb="Set a short on one cashup against an over on another — the same person on the wrong till or shift, usually. The amount moves once PumpIT's approver passes it.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->isChosen() ? ['branch_id' => $scope->branchId, 'date' => $scope->to] : []) }}">Daily Banking</a>
            @can('cash.pumpshortages.view')
                <a class="btn-ghost" href="{{ route('app.cash.pumpshortages') }}">Pump shortages</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('tillBalanceRefusal'))
        <x-notice tone="stop" title="That was not captured" style="margin-bottom:16px">
            <p>{{ session('tillBalanceRefusal') }}</p>
        </x-notice>
    @elseif (session('tillBalanceStatus'))
        <x-notice tone="info" style="margin-bottom:16px">
            <p>{{ session('tillBalanceStatus') }}</p>
        </x-notice>
    @endif

    @if ($errors->any())
        <x-notice tone="stop" title="That was not captured" style="margin-bottom:16px">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-notice>
    @endif

    <x-card title="Which cashups" sub="One site, a few days. Cashups whose variance is at least R{{ \App\Support\Format::n($floor, 2) }} either way are listed, and any a till balance is already waiting on.">
        <form method="GET" action="{{ route('app.cash.tillbalance') }}">
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
                <x-param name="date_from" label="From" type="date" :value="$scope->from" />
                <x-param name="date_to" label="To" type="date" :value="$scope->to" />
            </x-params>
            @if ($scope->employeeCode)
                <input type="hidden" name="employee" value="{{ $scope->employeeCode }}">
            @endif
            <div class="form-actions">
                <button type="submit" class="btn">Show the cashups</button>
            </div>
        </form>
    </x-card>

    @if ($live)
        <x-notice tone="warn" title="Capturing writes to PumpIT" style="margin-bottom:16px">
            <p>Capture writes the till balance into PumpIT exactly as its own till-balance screen does: two rows in
               <code>BRN_TillBalance</code> under the next till balance number — one taking the amount off a cashup, one
               putting it on another — each with the cashup's variance now and what it becomes. Nothing on either
               cashup moves yet: the till balance waits for approval in PumpIT, and approving it there posts it to
               both cashups' expenses as <em>Till Balance Expense</em>.</p>
            <p>Nothing is ever deleted. A till balance captured wrongly is declined by the approver in PumpIT and
               captured again.</p>
        </x-notice>
    @else
        <x-notice tone="info" title="Capturing to PumpIT is off" style="margin-bottom:16px">
            <p>This screen shows the site's cashups and the till balances on them. Nothing is written to PumpIT.</p>
        </x-notice>
    @endif

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Pick the site and the days, and its cashups with a short or an over appear here — each with what the till balances already waiting will do to it." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The cashups could not be read">
            <p>{{ $refusal }}</p>
        </x-notice>
    @else
        <x-card :title="($site ?? 'The site').' · '.\Illuminate\Support\Carbon::parse($scope->from)->format('j M').' – '.\Illuminate\Support\Carbon::parse($scope->to)->format('j M Y')" flush>
            <x-statstrip :stats="[
                ['label' => 'Short', 'value' => \App\Support\Format::n($summary['shorts']), 'note' => \App\Support\Format::r($summary['shortTotal']),
                 'tone' => $summary['shorts'] > 0 ? 'serious' : 'good'],
                ['label' => 'Over', 'value' => \App\Support\Format::n($summary['overs']), 'note' => \App\Support\Format::r($summary['overTotal']),
                 'tone' => $summary['overs'] > 0 ? 'warn' : 'good'],
                ['label' => 'Waiting for approval', 'value' => \App\Support\Format::n($summary['pending']),
                 'note' => \App\Support\Format::r($summary['pendingAmount']).' to move', 'tone' => $summary['pending'] > 0 ? 'warn' : 'neutral'],
            ]" />

            @if ($capture)
            <form method="POST" action="{{ route('app.cash.tillbalance.store') }}" id="till-balance"
                  data-confirm="Capture this till balance in PumpIT?"
                  data-confirm-text="Two rows are written to PumpIT's till balances under the next number: the amount comes off the first cashup and goes onto the second. Neither cashup moves until PumpIT's approver passes it. Nothing is deleted."
                  data-confirm-action="Capture in PumpIT" data-loader="Capturing…">
                @csrf
                @foreach ($scope->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
            @endif

                <x-table :count="$cashups->count()" procedure="agora.usp_Cash_GetTillBalance" dense tools
                         empty="No cashup at this site in these days has a variance to move.">
                    <x-slot:head>
                        <tr>
                            @if ($capture)
                                <th class="pick" title="The cashup the amount comes off — usually the one that is over">Off</th>
                                <th class="pick" title="The cashup the amount goes onto — usually the one that is short">Onto</th>
                            @endif
                            <th>Person</th>
                            <th>Day</th>
                            <th>Till</th>
                            <th>Shift</th>
                            <th class="num">Z read</th>
                            <th class="num">Variance</th>
                            <th class="num">Waiting</th>
                            <th class="num">Once posted</th>
                            <th>Cashup</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($cashups as $c)
                        @php($key = $cashupKey($c))
                        <tr>
                            @if ($capture)
                                <td class="pick">
                                    <input type="radio" name="from" value="{{ $key }}" @checked(old('from') === $key)
                                           @disabled(! $c->CanBalance) aria-label="Take the amount off {{ $key }}">
                                </td>
                                <td class="pick">
                                    <input type="radio" name="to" value="{{ $key }}" @checked(old('to') === $key)
                                           @disabled(! $c->CanBalance) aria-label="Put the amount onto {{ $key }}">
                                </td>
                            @endif
                            <td>{{ $c->EmployeeName ?? $c->EmployeeCode }}<br><span class="muted mono">{{ $c->EmployeeCode }}</span></td>
                            <td class="mono">{{ substr((string) $c->TransactionDate, 0, 10) }}</td>
                            <td><a href="{{ route('app.cash.banking', ['branch_id' => $c->BranchId, 'date' => substr((string) $c->TransactionDate, 0, 10), 'till' => $c->TillNo, 'shift' => $c->ShiftNo]) }}">{{ $c->TillDescription ?? 'Till '.$c->TillNo }}</a></td>
                            <td>{{ $c->ShiftDescription ?? $c->ShiftNo }}</td>
                            <td class="num">{{ \App\Support\Format::r($c->ZRead) }}</td>
                            <td class="num"><x-chip :tone="$tone($c->Variance)" :dot="false">{{ \App\Support\Format::r($c->Variance) }}</x-chip></td>
                            <td class="num">{{ (int) $c->PendingCount > 0 ? \App\Support\Format::r($c->Pending) : \App\Support\Format::NOTHING }}</td>
                            <td class="num">{{ (int) $c->PendingCount > 0 ? \App\Support\Format::r($c->VarianceIfPosted) : \App\Support\Format::NOTHING }}</td>
                            <td>
                                @if ($c->Problem)
                                    <x-chip tone="serious">cannot be balanced</x-chip><br><span class="muted">{{ $c->Problem }}</span>
                                @else
                                    <x-chip :tone="\Modules\Cash\Services\CashupService::statusTone($c->Status)">{{ $c->Status }}</x-chip>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>

                @if ($capture && $cashups->isNotEmpty())
                    <div class="form-actions inline-reason" style="margin:12px;max-width:640px">
                        <input type="text" name="amount" inputmode="decimal" value="{{ old('amount') }}" required
                               placeholder="Amount, e.g. 63.67" aria-label="The amount to move, in rands">
                        <input type="text" name="note" maxlength="400" value="{{ old('note') }}"
                               placeholder="Why (optional — kept in Agora)" aria-label="Why the till balance is needed">
                        <button type="submit" class="btn primary">Capture in PumpIT</button>
                    </div>
                @endif
            @if ($capture)
            </form>
            @endif
        </x-card>

        <x-card title="Till balances" sub="Every till balance with a side in these days, newest first — captured here or in PumpIT, and where it stands." flush style="margin-top:16px">
            <x-table :count="$balances->count()" procedure="agora.usp_Cash_GetTillBalance" dense tools
                     empty="No till balance has a side in these days.">
                <x-slot:head>
                    <tr>
                        <th>No</th>
                        <th>Off</th>
                        <th class="num">Variance</th>
                        <th>Onto</th>
                        <th class="num">Variance</th>
                        <th class="num">Amount</th>
                        <th>Where it stands</th>
                        <th>Captured</th>
                    </tr>
                </x-slot:head>
                @foreach ($balances as $b)
                    <tr>
                        <td class="mono">{{ $b->TillBalanceNo }}@if ((int) $b->Rows !== 2)<br><span class="muted">{{ $b->Rows }} rows</span>@endif</td>
                        <td>{{ $side($b->FromDate, $b->FromTill ?? $b->FromTillNo, $b->FromShift ?? $b->FromShiftNo, $b->FromEmployeeName ?? $b->FromEmployeeCode) }}</td>
                        <td class="num">{{ $b->FromDate === null ? \App\Support\Format::NOTHING : \App\Support\Format::r($b->FromVarianceBefore).' → '.\App\Support\Format::r($b->FromVarianceAfter) }}</td>
                        <td>{{ $side($b->ToDate, $b->ToTill ?? $b->ToTillNo, $b->ToShift ?? $b->ToShiftNo, $b->ToEmployeeName ?? $b->ToEmployeeCode) }}</td>
                        <td class="num">{{ $b->ToDate === null ? \App\Support\Format::NOTHING : \App\Support\Format::r($b->ToVarianceBefore).' → '.\App\Support\Format::r($b->ToVarianceAfter) }}</td>
                        <td class="num">{{ \App\Support\Format::r($b->Amount) }}</td>
                        <td>
                            <x-chip :tone="\Modules\Cash\Services\TillBalanceService::statusTone($b->Status)">{{ \Modules\Cash\Services\TillBalanceService::statusLabel($b->Status) }}</x-chip>
                            @if ($b->Status === 'approved')
                                <br><span class="muted">{{ $b->ApprovedByName ?? 'PumpIT' }}{{ $b->ApprovedAt ? ', '.\Illuminate\Support\Carbon::parse($b->ApprovedAt)->format('j M H:i') : '' }} · posted to {{ (int) $b->PostedLines }} of {{ (int) $b->Rows }} cashups</span>
                            @elseif ($b->Status === 'declined')
                                <br><span class="muted">{{ $b->DeclinedByName ?? 'PumpIT' }}{{ $b->DeclinedReason ? ': '.$b->DeclinedReason : '' }}</span>
                            @endif
                        </td>
                        <td>
                            {{ $b->CapturedByName ?? \App\Support\Format::NOTHING }}<br>
                            <span class="muted">{{ $b->CapturedIn === 'agora' ? 'in Agora' : 'in PumpIT' }}{{ $b->CapturedAt ? ', '.\Illuminate\Support\Carbon::parse($b->CapturedAt)->format('j M H:i') : '' }}</span>
                            @if ($b->Note)<br><span class="muted">{{ $b->Note }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>

        <x-explainer summary="What a till balance is, and what capturing one does" remember="cash-tillbalance">
            <x-notice tone="info" title="The figures">
                <p><strong>Variance</strong> is the cashup's actual total less its Z read — the figure PumpIT's own Daily
                   Banking grid shows. <strong>Waiting</strong> is what the till balances not yet approved will move it
                   by, and <strong>Once posted</strong> is where that leaves it.</p>
                <p>A till balance takes an amount <strong>off</strong> one cashup (its variance goes down by the amount)
                   and puts it <strong>onto</strong> another (its variance goes up by the same). To clear a short, put
                   the amount onto the short cashup and take it off the one that is over.</p>
            </x-notice>
            <x-notice tone="info" title="Approval and pump shortages">
                <p>Approving a till balance, and posting it to the two cashups, is done in PumpIT for now: its approver
                   list picks up a till balance captured here exactly as it picks up its own.</p>
                <p>A till balance is not a pump shortage. What a pump attendant is short reaches a cashup as an approved
                   staff short — see <a href="{{ route('app.cash.pumpshortages') }}">Pump shortages</a>.</p>
            </x-notice>
        </x-explainer>
    @endif
</x-app-shell>
