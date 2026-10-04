{{--
    Staff shorts, for a phone (plan §3.9: "every capture screen ... staff short
    ... gets a purpose-built mobile blade").

    RAISE A SHORT, and see what is open at the site — nothing else. The form is
    the desk's own (cash::staffshorts.partials.raise-form), stacked; the open
    shorts are a card each. Deciding and posting are a desk's: an approver reads
    the slips, and a post chooses a cashup.

    A refusal comes back as a toast with its AGORA code, and stays as a notice.

    Its own route (app.cash.staffshorts.phone), as the drop safe and cashup phone
    screens have: no DeviceDetector or pick() exists yet (E18).

    Props: $scope, $branches, $pinned, $refusal, $shorts, $reasons, $employees,
           $summary, $tills, $shifts, $writeMode
--}}
@php
    $user = auth()->user();
    $live = $writeMode === 'live';
    $canRaise = $live && (bool) $user?->can('cash.staffshort.raise');
    $open = $shorts->whereIn('Status', ['Waiting', 'Approved']);
@endphp

<x-app-shell title="Staff shorts">
    <x-page-head eyebrow="Cash" title="Staff shorts">
        <x-slot:info>
            <p>Raise a short against a person, and see what is waiting at the site. Approving, declining and posting
               are done at a desk.</p>
            @unless ($live)
                <p><strong>Writing to PumpIT is off.</strong> This shows the site's shorts; nothing is written to
                   PumpIT.</p>
            @endunless
        </x-slot:info>
    </x-page-head>

    @include('cash::staffshorts.partials.outcome')

    <form method="GET" action="{{ route('app.cash.staffshorts.phone') }}">
        <x-params collapsible :open="! $scope->isChosen()" remember="cash-staffshorts-phone" summary="Site">
            @unless ($pinned)
                <x-param name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$scope->branchId" />
            @endunless
        </x-params>
        <div class="form-actions"><button type="submit" class="btn">Show</button></div>
    </form>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Then the form to raise a short appears here, with what is waiting." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The staff shorts could not be read"><p>{{ $refusal }}</p></x-notice>
    @else
        @if ($canRaise)
            <x-card title="Raise a staff short" style="margin-bottom:12px">
                <x-slot:info>
                    <p>A pump attendant's short for a shift is a pump short. Nothing is charged until it is approved and
                       posted to a cashup.</p>
                </x-slot:info>
                @include('cash::staffshorts.partials.raise-form', ['from' => 'phone'])
            </x-card>
        @endif

        @forelse ($open as $s)
            <x-card :title="'Short '.$s->StaffShortsNo" :sub="($s->EmployeeName ?? $s->EmployeeCode).' · '.$s->ReasonLabel" style="margin-bottom:12px">
                <x-facts>
                    <x-fact label="Amount" :value="\App\Support\Format::r($s->AmountShort)" />
                    <x-fact label="Day" :value="\Illuminate\Support\Carbon::parse($s->TransactionDate)->format('j M')" />
                </x-facts>
                <p><x-chip :tone="\Modules\Cash\Services\StaffShortService::statusTone($s->Status)">{{ \Modules\Cash\Services\StaffShortService::statusWords($s->Status) }}</x-chip></p>
                @if ($s->Note)<p class="muted">{{ $s->Note }}</p>@endif
            </x-card>
        @empty
            <x-empty-state title="Nothing waiting" text="No short at this site is waiting for an approver or for a cashup." />
        @endforelse

        <p class="muted">Approving, declining and posting are done at a desk:
            <a href="{{ route('app.cash.staffshorts', $scope->withShort(null)->query()) }}">Staff shorts</a>.</p>
    @endif
</x-app-shell>
