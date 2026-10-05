{{--
    Staff shorts (T040) — PumpIT's staff-short capture, its approval list and
    its posting, for one site.

    A staff short is money a named person is answerable for. It is RAISED (a
    number from PumpIT's own counter, a reason code, a note), an approver
    APPROVES or DECLINES it with a reason, and an approved short is POSTED —
    put on one cashup's pump shortages, which is where the cashup counts it and
    where the deduction schedule charges it.

    Scope (site; the days listed beside the open shorts) → the figures → raise a
    short → the short opened, with its presses → waiting, approved, the period.

    WHAT REACHES PUMPIT, and nothing else, while cash.staffshort.write_mode is
    'live': Raise (a BRN_StaffShorts row and the section 2 counter), Approve,
    Decline and Withdraw (that row's columns), Post (the cashup's pump-shortages line, the
    short's flag and the header's totals — cash.cashup.write_mode as well).

    The standing explanation — the life of a short, the reasons, and what
    each press writes to PumpIT — sits behind the info button beside the title
    (ZRP, 4 Oct 2026). Refusals, the figures and the opened short stay on the
    page; every press that writes says so in its confirmation.

    Props: $scope, $branches, $pinned, $refusal, $shorts, $reasons, $employees,
           $summary, $trail, $tills, $shifts, $opened, $writeMode, $postMode
--}}
@php
    $user = auth()->user();
    $live = $writeMode === 'live';
    $canRaise = $live && (bool) $user?->can('cash.staffshort.raise');
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
    $open = $shorts->whereIn('Status', ['Waiting', 'Approved']);
    $rest = $shorts->whereNotIn('Status', ['Waiting', 'Approved']);
    $day = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : \App\Support\Format::NOTHING;
@endphp

<x-app-shell title="Staff shorts">
    <x-page-head eyebrow="Branch functions · Cash" title="Staff shorts">
        <x-slot:info>
            <p>A short is <strong>raised</strong> against a person on the site's employee list with one of four
               reasons — pump short, cashier short, stock loss, or other with a note. An <strong>approver</strong>,
               emailed when it is raised, approves or declines it; the raiser is emailed the decision. It is charged
               once <strong>posted</strong> to a cashup's pump shortages, from here or from Daily Banking. An approved
               short not yet on a cashup can be <strong>withdrawn</strong>, with a reason.</p>
            <p>A short a <strong>cash-bag match</strong> raised is never put on a cashup — the cashup already counted
               those bags — and deductions read it directly. Shorts raised in PumpIT carry free text; each reads as the
               reason its text matches, and the text as typed is kept as the note.</p>
            @if ($live)
                <p><strong>Raising, deciding and posting write to PumpIT</strong> (<code>BRN_StaffShorts</code>,
                   and the cashup's pump shortages, <code>BRN_DailyBankingEmployees</code>, which moves its variance).
                   What each press wrote, and what it replaced, is kept in Agora. Nothing is deleted.</p>
            @else
                <p><strong>Writing to PumpIT is off.</strong> This screen shows the site's staff shorts; nothing is
                   written to PumpIT.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            @if ($scope->isChosen() && $summary)
                <x-chip :tone="(int) $summary->Waiting > 0 ? 'warn' : 'good'">Waiting: {{ \App\Support\Format::n((int) ($summary->Waiting ?? 0)) }}</x-chip>
            @endif
            <a class="btn-ghost" href="{{ route('app.cash.staffshorts.list') }}">Staff shorts list</a>
            <a class="btn-ghost" href="{{ route('app.cash.staffshorts.phone', $scope->withShort(null)->query()) }}">On a phone</a>
            @can('cash.cashup.view')
                <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->isChosen() ? ['branch_id' => $scope->branchId] : []) }}">Daily Banking</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @include('cash::staffshorts.partials.outcome')

    <x-card title="Which site">
        <x-slot:info>
            <p>Shorts waiting for an approver, or approved and not on a cashup, are listed whatever their date; the
               days choose the rest.</p>
        </x-slot:info>
        <form method="GET" action="{{ route('app.cash.staffshorts') }}">
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
            <div class="form-actions">
                <button type="submit" class="btn">Show the shorts</button>
            </div>
        </form>
    </x-card>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Pick the site, and its staff shorts appear here — with the form to raise one." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The staff shorts could not be read">
            <p>{{ $refusal }}</p>
        </x-notice>
    @else
        <x-card :title="($site ?? 'The site').' · staff shorts'" flush>
            <x-statstrip :stats="[
                ['label' => 'Waiting', 'value' => \App\Support\Format::n($summary->Waiting ?? 0), 'note' => \App\Support\Format::r($summary->WaitingAmount ?? 0).' for an approver',
                 'tone' => (int) ($summary->Waiting ?? 0) > 0 ? 'warn' : 'good'],
                ['label' => 'Approved', 'value' => \App\Support\Format::n($summary->Approved ?? 0), 'note' => \App\Support\Format::r($summary->ApprovedAmount ?? 0).' not on a cashup yet',
                 'tone' => (int) ($summary->Approved ?? 0) > 0 ? 'serious' : 'neutral'],
                ['label' => 'Posted', 'value' => \App\Support\Format::n($summary->Posted ?? 0), 'note' => \App\Support\Format::r($summary->PostedAmount ?? 0).' in these days', 'tone' => 'neutral'],
                ['label' => 'Declined', 'value' => \App\Support\Format::n($summary->Declined ?? 0), 'tone' => 'neutral'],
            ]" />
        </x-card>

        @if ($opened)
            @include('cash::staffshorts.partials.decide')
        @elseif ($scope->shortNo !== null)
            <x-notice tone="info" title="Short {{ $scope->shortNo }} is not at this site" style="margin-top:16px">
                <p>Choose its site, or open it from the Staff shorts list.</p>
            </x-notice>
        @endif

        @if ($canRaise && ! $opened)
            <x-card title="Raise a staff short" style="margin-top:16px">
                <x-slot:info>
                    <p>A pump attendant's short for a shift is raised as a pump short — that is what the cashup's pump
                       shortages count once it is approved and posted.</p>
                    <p>The cashup till and shift are optional: given, posting offers that cashup first.</p>
                </x-slot:info>
                @include('cash::staffshorts.partials.raise-form', ['from' => 'desk'])
            </x-card>
        @endif

        {{-- No sub-line: the titles say what each list holds, and the scope
             card's info says the open ones ignore the dates. --}}
        @foreach (['open' => [$open, 'Waiting and approved', 'Nothing is waiting at this site, and nothing approved is off a cashup.'],
                   'rest' => [$rest, 'Posted and declined', 'Nothing else was raised at this site in these days.']] as $key => [$rows, $title, $empty])
            <x-card :title="$title" flush style="margin-top:16px">
                <x-table :count="$rows->count()" procedure="agora.usp_Cash_GetStaffShorts" dense tools :empty="$empty">
                    <x-slot:head>
                        <tr>
                            <th>Short</th>
                            <th>Day</th>
                            <th>Against</th>
                            <th class="num">Amount</th>
                            <th>Reason</th>
                            <th>Note</th>
                            <th>Status</th>
                            <th>Raised</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($rows as $s)
                        <tr>
                            <td class="mono"><a href="{{ route('app.cash.staffshorts', $scope->withShort((int) $s->StaffShortsNo)->query()) }}">{{ $s->StaffShortsNo }}</a></td>
                            <td>{{ $day($s->TransactionDate) }}</td>
                            <td>{{ $s->EmployeeName ?? $s->EmployeeCode }}<br><span class="muted mono">{{ $s->EmployeeCode }}</span></td>
                            <td class="num">{{ \App\Support\Format::r($s->AmountShort) }}</td>
                            <td>{{ $s->ReasonLabel }}</td>
                            <td>{{ $s->Note }}</td>
                            <td data-tone="{{ \Modules\Cash\Services\StaffShortService::statusTone($s->Status) }}">
                                <x-chip :tone="\Modules\Cash\Services\StaffShortService::statusTone($s->Status)">{{ $s->Status }}</x-chip>
                                @if ($s->Status === 'Posted')<br><span class="muted">till {{ $s->PostedTillNo }}, shift {{ $s->PostedShiftNo }}</span>@endif
                            </td>
                            <td><span class="muted">{{ $s->RaisedIn === 'Agora' ? 'in Agora' : 'in PumpIT' }}{{ $s->RaisedBy ? ', '.$s->RaisedBy : '' }}</span></td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @endforeach

    @endif
</x-app-shell>
