{{--
    Tab two: what balancing must refuse to hide.

    Nothing here is ticked, because nothing here is actionable ON THIS SCREEN.
    A U-class row is a conversation with a branch about paperwork, not a count
    somebody amends — and putting a tick box beside it would invite exactly the
    thing the whole method note argues against: chasing a data fault with count
    amendments and spreading it across innocent shifts.
--}}
{{-- The legend that stood here — "The classes" and "The audit", two folded
     tables — is the codes modal now (partials/codes-modal, opened from the "i"
     below and from every code badge), in plain words for the people at the
     sites rather than in the client's pack's terms (Ryan, 7 Oct 2026). --}}
{{-- The figure stays on screen; why it matters is behind the info button
     (ZRP, 4 Oct 2026). --}}
<x-card title="Exceptions"
        :sub="'Unrecorded issue on this run: '.\App\Support\Format::r($run->NetOverValue).' across '
              .\App\Support\Format::n($run->BlockedChains).' reported '.Str::plural('chain', $run->BlockedChains)"
        flush info-modal="stockrecon-codes">
    <x-slot:actions>
        <button type="button" class="btn-ghost" data-modal-open="stockrecon-codes">What the codes mean</button>
    </x-slot:actions>
    <div style="padding:16px" id="exception-types">
        <x-card title="Filter by exception type" collapsible remember="stockrecon-exception-types"
                :sub="($exceptionNavigation['help']['title'] ?? ($exceptionNavigation['active'] === 'all' ? 'All types' : 'Multiple code filters'))
                    .' · '.(count($exceptionNavigation['items']) - 1).' '.Str::plural('type', count($exceptionNavigation['items']) - 1).' available'"
                style="margin-bottom:0">
            <p class="muted">Choose a type to narrow the list. Pill counts are item/shift lines for the whole run, before search and column filters.
                A line can have both a count exception and a hand-balancing check; All types counts it once.</p>
            <x-tabs :items="$exceptionNavigation['items']" :active="$exceptionNavigation['active']"
                    chips label="Exception types" />
        </x-card>
        @if ($exceptionNavigation['help'])
            <x-notice tone="info" :title="$exceptionNavigation['help']['title']" style="margin-top:12px">
                <p>{{ $exceptionNavigation['help']['plain'] ?? 'Open the code guide for more information.' }}</p>
                @if (isset($exceptionNavigation['help']['todo']))
                    <p><strong>What to do:</strong> {{ $exceptionNavigation['help']['todo'] }}</p>
                @endif
            </x-notice>
        @elseif ($exceptionNavigation['active'] !== 'all')
            <p class="muted" style="margin-top:12px">Code filters are active in the grid below. Choose a type to replace them, or All types to clear them.</p>
        @endif
    </div>
    {{-- A real grid: header filters, an export, saved column widths and a
         footer total somebody takes to a branch meeting. The branch selector is
         off — a run is about one site and the scope came with it. --}}
    <x-data-grid :grid="$grid" />
</x-card>
