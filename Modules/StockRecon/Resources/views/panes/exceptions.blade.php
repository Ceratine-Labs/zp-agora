{{--
    Tab two: what balancing must refuse to hide.

    Nothing here is ticked, because nothing here is actionable ON THIS SCREEN.
    A U-class row is a conversation with a branch about paperwork, not a count
    somebody amends — and putting a tick box beside it would invite exactly the
    thing the whole method note argues against: chasing a data fault with count
    amendments and spreading it across innocent shifts.
--}}
{{-- The legend, as a table rather than as bespoke markup. It IS a small
     result set — nine rows of code, name, meaning and test — and a card of
     hand-written divs would be a component nobody else could reuse. Collapsed,
     because on a screen that has an answer the vocabulary is reference rather
     than the point. --}}
<x-card title="The classes" collapsible flush>
    <x-table dense :count="count($classes)">
        <x-slot:head>
            <tr><th class="l">Class</th><th class="l">What it is</th><th class="l">The test</th><th class="l">Balanced?</th></tr>
        </x-slot:head>
        @foreach ($classes as $code => $class)
            <tr>
                <td class="l">
                    <x-chip :tone="$class['tone']" :dot="false">{{ $code }}</x-chip>
                </td>
                <td class="l">
                    <strong>{{ $class['label'] }}</strong>
                    <br><span class="muted">{{ $class['blurb'] }}</span>
                </td>
                <td class="l mono muted">{{ $class['test'] }}</td>
                <td class="l muted">{{ $class['balanced'] ?? (($class['blocks'] ?? false) ? 'No — reported' : 'Yes') }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

{{-- The pack's audit of the hand balancing, same shape. Its own card because it
     answers a different question: not "what do the counts say" but "what did
     somebody already do to them in PumpIT". --}}
<x-card title="The audit" sub="Hand balancing already in PumpIT" collapsible flush>
    <x-table dense :count="count($audit)">
        <x-slot:head>
            <tr><th class="l">Code</th><th class="l">What it is</th></tr>
        </x-slot:head>
        @foreach ($audit as $code => $check)
            <tr>
                <td class="l"><x-chip :tone="$check['tone']" :dot="false">{{ $code }}</x-chip></td>
                <td class="l"><strong>{{ $check['label'] }}</strong>
                    <br><span class="muted">{{ $check['blurb'] }}</span></td>
            </tr>
        @endforeach
    </x-table>
</x-card>

{{-- The figure stays on screen; why it matters is behind the info button
     (ZRP, 4 Oct 2026). --}}
<x-card title="Exceptions"
        :sub="'Unrecorded issue on this run: '.\App\Support\Format::r($run->NetOverValue).' across '
              .\App\Support\Format::n($run->BlockedChains).' reported '.Str::plural('chain', $run->BlockedChains)"
        flush info-wide>
    <x-slot:info>
        <p>A chain that ends <strong>over</strong> sold more than it received: stock that arrived without an
           issue. No closing count changes that.</p>
        <p>Codes are the client's weekly pack's where it defines the test (S1, S3, S5, S10a, S10b, E1). A break
           of 3 or fewer keeps its chain going and still shows here, with its qty in <strong>Break</strong>.
           <strong>Audit</strong> is the pack's check on hand balancing already in PumpIT.</p>
        <p>Dormant shifts are left out of every rate. Sorted worst class first, then biggest value.</p>
    </x-slot:info>
    {{-- A real grid: header filters, an export, saved column widths and a
         footer total somebody takes to a branch meeting. The branch selector is
         off — a run is about one site and the scope came with it. --}}
    <x-data-grid :grid="$grid" />
</x-card>
