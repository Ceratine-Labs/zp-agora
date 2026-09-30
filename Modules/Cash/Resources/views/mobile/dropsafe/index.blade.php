{{--
    Drop safe, for a phone (plan §3.9: "every capture screen ... drop safe ...
    gets a purpose-built mobile blade").

    DROP A BAG, and see what is in the safe — nothing else. The form is the
    desk's own (cash::dropsafe.partials.drop-form), stacked; the safe is a card
    per bag with its "In the safe" chip, and the chip above them carries the
    count. Collecting, the collections list and the grid are a desk's: a
    collection is ticked off a list of bags against the security company's
    slip, which is the job a phone does worst.

    A refusal comes back as a toast with its AGORA code, and stays as a notice.

    Its own route (app.cash.dropsafe.phone), as the Z-read and cashup phone
    screens have: no DeviceDetector or pick() exists yet (E18).

    Props: $scope, $branches, $pinned, $refusal, $bags, $collections, $reasons,
           $limitReasons, $employees, $summary, $editing, $writeMode, $threshold
--}}
@php
    $user = auth()->user();
    $live = $writeMode === 'live';
    $canDrop = $live && (bool) $user?->can('cash.dropsafe.save');
    $canEdit = $live && (bool) $user?->can('cash.dropsafe.edit');
    $inSafe = (int) ($summary->InSafeBags ?? 0);
@endphp

<x-app-shell title="Drop safe">
    <x-page-head eyebrow="Cash" title="Drop safe"
                 blurb="Drop a bag into the manual safe, and see what is waiting for the security company." />

    @include('cash::dropsafe.partials.outcome')

    <form method="GET" action="{{ route('app.cash.dropsafe.phone') }}">
        <x-params collapsible :open="! $scope->isChosen()" remember="cash-dropsafe-phone" summary="Site">
            @unless ($pinned)
                <x-param name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$scope->branchId" />
            @endunless
        </x-params>
        <div class="form-actions"><button type="submit" class="btn">Show</button></div>
    </form>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Then what is in its safe appears here, with the form to drop a bag." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The safe could not be read"><p>{{ $refusal }}</p></x-notice>
    @else
        <p>
            <x-chip :tone="\Modules\Cash\Services\DropSafeService::inSafeTone($inSafe)">In the safe: {{ \App\Support\Format::n($inSafe) }}{{ $inSafe > 0 ? ' · '.\App\Support\Format::r($summary->InSafeAmount ?? 0) : '' }}</x-chip>
        </p>

        @if ($live && ($canDrop || ($editing && $canEdit)))
            <x-card :title="$editing ? 'Correct bag '.$editing->BagNo : 'Drop a bag'"
                    sub="Every bag needs the manager who witnessed it; one over {{ \App\Support\Format::r($threshold) }} needs a reason too."
                    style="margin-bottom:12px">
                @include('cash::dropsafe.partials.drop-form', ['from' => 'phone'])
            </x-card>
        @elseif (! $live)
            <x-notice tone="info" title="Writing to PumpIT is off"><p>This shows the safe; nothing is written to PumpIT.</p></x-notice>
        @endif

        @forelse ($bags as $b)
            <x-card :title="'Bag '.$b->BagNo" :sub="($b->CashierName ?? $b->CashierCode).' · witnessed by '.($b->ManagerName ?? $b->ManagerCode)" style="margin-bottom:12px">
                <x-facts>
                    <x-fact label="Amount" :value="\App\Support\Format::r($b->Amount)" />
                    <x-fact label="Dropped" :value="\Illuminate\Support\Carbon::parse($b->DropDate)->format('j M').' '.$b->DropTime" />
                    <x-fact label="Why" :value="$b->ReasonForManualBag ?? \App\Support\Format::NOTHING" />
                </x-facts>
                <p>
                    <x-chip tone="crit">In the safe</x-chip>
                    @if ($b->OnCashup)<x-chip tone="neutral">On a cashup</x-chip>@endif
                    @if ($b->NeedsReason)<x-chip tone="serious">no reason given</x-chip>@endif
                </p>
                @if ($canEdit && $b->CanEdit)
                    <p><a href="{{ route('app.cash.dropsafe.phone', $scope->withBag($b->BagNo)->query()) }}">Correct this bag</a></p>
                @endif
            </x-card>
        @empty
            <x-empty-state title="The safe is empty" text="Every bag at this site has been collected." />
        @endforelse

        <p class="muted">Collecting bags for the security company is done at a desk:
            <a href="{{ route('app.cash.dropsafe', $scope->withBag(null)->query()) }}">Drop safe</a>.</p>
    @endif
</x-app-shell>
