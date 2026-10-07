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
    {{-- A real grid: header filters, an export, saved column widths and a
         footer total somebody takes to a branch meeting. The branch selector is
         off — a run is about one site and the scope came with it. --}}
    <x-data-grid :grid="$grid" />
</x-card>
