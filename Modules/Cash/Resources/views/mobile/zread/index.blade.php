{{--
    Z-read confirm, for a phone (plan §3.9: "every capture screen ... Z-read
    confirm ... gets a purpose-built mobile blade").

    CONFIRM-ONLY. A person on a phone confirms what the system proposes, one
    Z-read at a time, and does nothing else: no setting by hand, no bulk
    press, no grid. Each card is the proposal with its evidence and one button,
    which accepts it and saves it in the same transaction (ZreadService::
    confirm). What the system cannot call is counted and left for a desk,
    because choosing a till, a shift and a person from three long lists is the
    job a phone does worst.

    Its own route (app.cash.zread.confirm) for now: there is no DeviceDetector
    or pick() yet (E18), so nothing sends a phone here automatically.

    Props: $scope, $branches, $pinned, $refusal, $confirmable, $forADesk, $stampMode
--}}
@php
    $canConfirm = (bool) (auth()->user()?->can('cash.zread.allocate') && auth()->user()?->can('cash.zread.commit'));
    $live = ($stampMode ?? 'journal') === 'live';
    // The same words as the desk's "Save just this one" — a Confirm here is
    // the same save, and on a live stamp it writes PumpIT too.
    $confirmText = $live
        ? 'It becomes allocated in Agora and its till, shift and person are written onto its PumpIT row. What the row held before is recorded, so the press can be reversed.'
        : 'It becomes allocated in Agora. The PumpIT stamp is off, so nothing is written to PumpIT.';
@endphp

<x-app-shell title="Confirm Z-reads">
    <x-page-head eyebrow="Cash" title="Confirm Z-reads">
        <x-slot:info>
            <p>What the system proposes, one Z-read at a time. Confirm what is right; anything it cannot call waits for
               a desk, on ZREAD Allocation.</p>
            @if ($live)
                <p><strong>Confirm writes to PumpIT:</strong> it saves the Z-read in Agora and writes its till, shift
                   and person onto its PumpIT row. What the row held before is recorded, so it can be reversed from
                   ZREAD Allocation at a desk.</p>
            @else
                <p><strong>The PumpIT stamp is off.</strong> Each Confirm saves the Z-read in Agora only; nothing is
                   written to PumpIT.</p>
            @endif
        </x-slot:info>
    </x-page-head>

    @if (session('zreadRefusal'))
        <x-notice tone="stop" title="That was not saved"><p>{{ session('zreadRefusal') }}</p></x-notice>
    @elseif (session('zreadStatus'))
        <x-notice tone="info"><p>{{ session('zreadStatus') }}</p></x-notice>
    @endif

    <form method="GET" action="{{ route('app.cash.zread.confirm') }}">
        <x-params collapsible :open="! $scope->isChosen()" remember="cash-zread-confirm" summary="Site and days">
            @unless ($pinned)
                <x-param name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$scope->branchId" />
            @endunless
            <x-param name="date_from" label="From" type="date" :value="$scope->from" />
            <x-param name="date_to" label="To" type="date" :value="$scope->to" />
        </x-params>
        <div class="form-actions"><button type="submit" class="btn">Show</button></div>
    </form>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Then the Z-reads waiting to be confirmed appear here." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The Z-reads could not be read"><p>{{ $refusal }}</p></x-notice>
    @elseif ($confirmable->isEmpty())
        <x-empty-state title="Nothing to confirm"
                       :text="$forADesk > 0
                           ? $forADesk.' Z-read'.($forADesk === 1 ? '' : 's').' need a person to choose — at a desk, on ZREAD Allocation.'
                           : 'Every Z-read in these days is allocated.'" />
    @else
        @if ($forADesk > 0)
            <x-notice tone="warn">
                <p>{{ $forADesk }} more {{ \Illuminate\Support\Str::plural('Z-read', $forADesk) }} the system cannot call — set {{ $forADesk === 1 ? 'it' : 'them' }} at a desk, on
                   <a href="{{ route('app.cash.zread', $scope->query()) }}">ZREAD Allocation</a>.</p>
            </x-notice>
        @endif

        @foreach ($confirmable as $z)
            @php($staged = $z->State === 'staged')
            <x-proposal :confidence="$staged ? ($z->StagedConfidence ?? 'manual') : $z->Confidence"
                        :eyebrow="$z->ZReadDate.' · '.$z->Location.' terminal '.$z->TerminalNo.' · '.\App\Support\Format::r($z->Sales)"
                        :headline="$staged
                            ? collect([$z->EmployeeName ?? $z->EmployeeCode, $z->ShiftDescription, $z->TillDescription])->filter()->implode(' · ')
                            : collect([$z->ProposedEmployeeName ?? $z->ProposedEmployeeCode, $z->ProposedShiftDescription, $z->ProposedTillDescription])->filter()->implode(' · ')"
                        :evidence="$staged ? ['Staged — confirming saves it.'] : [$z->Evidence, $z->ShiftEvidence]"
                        style="margin-bottom:12px">
                @if ($canConfirm)
                    <x-slot:actions>
                        <form method="POST" action="{{ route('app.cash.zread.confirm.store') }}"
                              data-confirm="Confirm this Z-read?" data-confirm-text="{{ $confirmText }}"
                              data-confirm-action="Confirm">
                            @csrf
                            @include('cash::zread.partials.scope-inputs')
                            @foreach (\Modules\Cash\Support\ZreadKey::fromRow($z)->query() as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="btn primary">Confirm</button>
                        </form>
                    </x-slot:actions>
                @endif
            </x-proposal>
        @endforeach
    @endif
</x-app-shell>
