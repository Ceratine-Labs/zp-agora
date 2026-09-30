{{--
    One Z-read, opened under its row: what it is, what the system proposes and
    why, the three things a person can do with it, and who normally works that
    terminal — the pattern a person is agreeing with or overriding.

    Every form here posts the Z-read's full key (ZreadKey) and the screen's
    scope, and comes back to the screen. Accept posts no values at all: the
    procedure reads the proposal itself, so what is staged is what the system
    said rather than what a request claimed it said.

    Props: $scope, $key, $zread, $history, $tills, $shifts, $employees
--}}
@php
    $canAllocate = (bool) auth()->user()?->can('cash.zread.allocate');
    $canCommit = (bool) auth()->user()?->can('cash.zread.commit');
    $state = $zread->State;
    $proposal = collect([
        $zread->ProposedEmployeeName ?? $zread->ProposedEmployeeCode,
        $zread->ProposedShiftDescription ?? ($zread->ProposedEmployeeCode ? 'shift to choose' : null),
        $zread->ProposedTillDescription,
    ])->filter()->implode(' · ');
    $prefill = [
        'till' => $state === 'open' ? $zread->ProposedTillNo : $zread->TillNo,
        'shift' => $state === 'open' ? $zread->ProposedShiftNo : $zread->ShiftNo,
        'employee' => $state === 'open' ? $zread->ProposedEmployeeCode : $zread->EmployeeCode,
    ];
@endphp

<div class="two-col">
    <div>
        <p class="eyebrow">{{ $zread->Location }} · terminal {{ $zread->TerminalNo }} · EOD {{ $zread->EodCounter }} · log {{ $zread->LogFile }} · operator ID {{ $zread->PosUserId }}</p>

        <x-facts :columns="3">
            <x-fact label="Sales" :value="\App\Support\Format::r($zread->Sales)">
                {{ \Illuminate\Support\Carbon::parse($zread->ZReadDate)->toDateString() }}, as the till printed it.
            </x-fact>
            <x-fact label="Now" :value="$state === 'open' ? 'Not allocated' : ($state === 'staged' ? 'Staged' : 'Allocated')"
                    :tone="$state === 'allocated' ? 'good' : ($state === 'staged' ? 'warn' : 'serious')">
                @if ($state === 'allocated')
                    {{ collect([$zread->EmployeeName ?? $zread->EmployeeCode, $zread->ShiftDescription, $zread->TillDescription])->filter()->implode(' · ') }},
                    {{ $zread->AllocatedBy === 'agora' ? 'saved in Agora' : 'on the PumpIT ZREAD Update screen' }}.
                @elseif ($state === 'staged')
                    {{ collect([$zread->EmployeeName ?? $zread->EmployeeCode, $zread->ShiftDescription, $zread->TillDescription])->filter()->implode(' · ') }} — Update ZREAD saves it.
                @elseif ($zread->IsPartlyAllocated)
                    PumpIT carries part of an allocation ({{ collect([$zread->LegacyTillNo ? 'till '.$zread->LegacyTillNo : null, $zread->LegacyShiftNo ? 'shift '.$zread->LegacyShiftNo : null, $zread->LegacyEmployeeCode])->filter()->implode(', ') }}) and not all three.
                @else
                    Nobody has said which till, shift or person yet.
                @endif
                @if ($zread->ChangedInPumpIt ?? false)
                    <strong>Changed in PumpIT:</strong> Agora saved and wrote an allocation for this Z-read, and it has
                    since been allocated differently on the PumpIT screen. That later allocation is the one shown, and
                    the Agora press can no longer be reversed.
                @endif
            </x-fact>
            <x-fact label="Grounds" :value="$zread->PriorTotal ? \App\Support\Format::n($zread->PriorZreads).' of '.\App\Support\Format::n($zread->PriorTotal) : null">
                prior Z-reads behind the proposal.
            </x-fact>
        </x-facts>

        <x-proposal :confidence="$zread->Confidence"
                    :eyebrow="$zread->Confidence === 'manual' ? 'The system cannot say' : 'The system suggests'"
                    :headline="$zread->Confidence === 'manual' ? null : $proposal"
                    :evidence="[$zread->Evidence, $zread->ShiftEvidence]"
                    style="margin-top:14px">
            @if ($canAllocate)
                <x-slot:actions>
                    @if ($zread->IsComplete && $state !== 'allocated')
                        <form method="POST" action="{{ route('app.cash.zread.accept') }}">
                            @csrf
                            @include('cash::zread.partials.scope-inputs')
                            @foreach ($key->query() as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="btn primary">Accept suggestion</button>
                        </form>
                    @endif
                    @if ($state === 'staged')
                        <form method="POST" action="{{ route('app.cash.zread.clear') }}">
                            @csrf
                            @include('cash::zread.partials.scope-inputs')
                            @foreach ($key->query() as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="btn">Clear what is staged</button>
                        </form>
                        @if ($canCommit)
                            <form method="POST" action="{{ route('app.cash.zread.commit') }}"
                                  data-confirm="Save this Z-read's allocation?"
                                  data-confirm-text="{{ ($stampMode ?? 'journal') === 'live'
                                      ? 'It becomes allocated in Agora and its till, shift and person are written onto its PumpIT row. What the row held before is recorded, so the press can be reversed.'
                                      : 'It becomes allocated in Agora. The PumpIT stamp is off, so nothing is written to PumpIT.' }}"
                                  data-confirm-action="Save it">
                                @csrf
                                @include('cash::zread.partials.scope-inputs')
                                @foreach ($key->query() as $name => $value)
                                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit" class="btn">Save just this one</button>
                            </form>
                        @endif
                    @endif
                </x-slot:actions>
            @endif
        </x-proposal>

        @if ($canAllocate)
            <form method="POST" action="{{ route('app.cash.zread.set') }}" style="margin-top:14px">
                @csrf
                @include('cash::zread.partials.scope-inputs')
                @foreach ($key->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <div class="field-row">
                    <x-field name="till_no" label="Till" :value="$prefill['till']"
                             :choices="['' => 'Choose a till'] + $tills->mapWithKeys(fn ($t) => [$t->TillNo => $t->TillDescription.' ('.$t->TillNo.')'])->all()" />
                    <x-field name="shift_no" label="Shift" :value="$prefill['shift']"
                             :choices="['' => 'Choose a shift'] + $shifts->mapWithKeys(fn ($s) => [$s->ShiftNo => $s->ShiftDescription])->all()" />
                    <x-field name="employee_code" label="Employee" :value="$prefill['employee']"
                             :choices="['' => 'Choose a person'] + $employees->mapWithKeys(fn ($e) => [$e->EmployeeCode => $e->EmployeeName.' ('.$e->EmployeeCode.')'.($e->IsActive ? '' : ' — inactive')])->all()" />
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Set this Z-read</button>
                    <span class="field-help">Stages it by hand. Update ZREAD saves it.</span>
                </div>
            </form>
        @endif
    </div>

    <div>
        <x-table :count="$history->count()" procedure="agora.usp_Cash_ZreadTerminalHistory" dense
                 empty="Nobody has been allocated to this terminal in the history window.">
            <x-slot:head>
                <tr>
                    <th>Who normally runs terminal {{ $zread->TerminalNo }}</th>
                    <th>Shift</th>
                    <th>Till</th>
                    <th>Operator IDs</th>
                    <th class="num">Z-reads</th>
                </tr>
            </x-slot:head>
            @foreach ($history as $h)
                <tr>
                    <td>{{ $h->EmployeeName ?: $h->EmployeeCode }} <span class="muted mono">{{ $h->EmployeeCode }}</span></td>
                    <td>{{ $h->ShiftDescription ?? $h->ShiftNo }}</td>
                    <td>{{ $h->TillDescription ?? $h->TillNo }}</td>
                    <td class="mono">{{ $h->OperatorIds }}</td>
                    <td class="num">{{ \App\Support\Format::n($h->Zreads) }}</td>
                </tr>
            @endforeach
        </x-table>
    </div>
</div>
