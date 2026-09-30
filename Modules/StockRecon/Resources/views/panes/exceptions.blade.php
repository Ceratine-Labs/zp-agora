{{--
    Tab two: what balancing must refuse to hide.

    Nothing here is ticked, because nothing here is actionable ON THIS SCREEN.
    A U-class row is a conversation with a branch about paperwork, not a count
    somebody amends — and putting a tick box beside it would invite exactly the
    thing the whole method note argues against: chasing a data fault with count
    amendments and spreading it across innocent shifts.
--}}
<x-notice tone="warn" title="Balancing well is what makes this visible" style="margin-bottom:16px">
    <p>The total variance across a window is fixed by the dates, so a chain that ends <strong>over</strong>
       sold more than it ever received. That is stock which entered the store without an issue being
       captured, and <em>no set of closing counts changes it</em>. On this run it is
       <strong>{{ \App\Support\Format::r($run->NetOverValue) }}</strong> across
       {{ \App\Support\Format::n($run->BlockedChains) }} reported
       {{ Str::plural('chain', $run->BlockedChains) }}.</p>
    <p>The codes are the client's where the client's weekly pack defines the test — S1, S3, S5, S10a,
       S10b, E1 — so a line reads the same here and in the master book. The <strong>Audit</strong> column is
       the pack's check on the hand balancing already in PumpIT (S7, S8, S11, S12, S13, S4): what somebody did
       that the rules do not allow. A commit on a clear chain puts it back.</p>
    <p>Dormant shifts are out of every denominator below. A chain where nine of twelve shifts never traded
       is not "short a quarter of the time" — it is short on one of three real shifts, and the rate that
       goes to a branch has to say so.</p>
</x-notice>

{{-- The legend, as a table rather than as bespoke markup. It IS a small
     result set — nine rows of code, name, meaning and test — and a card of
     hand-written divs would be a component nobody else could reuse. Collapsed,
     because on a screen that has an answer the vocabulary is reference rather
     than the point. --}}
<x-card title="The classes" sub="Ranked most serious first, which is also the order the grid comes in"
        collapsible flush>
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
                <td class="l muted">{{ ($class['blocks'] ?? false) ? 'No — reported' : 'Yes' }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

{{-- The pack's audit of the hand balancing, same shape. Its own card because it
     answers a different question: not "what do the counts say" but "what did
     somebody already do to them in PumpIT". --}}
<x-card title="The audit" sub="The client's check on balancing already done by hand in PumpIT"
        collapsible flush>
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

<x-card title="Exceptions"
        sub="Every line this run reported instead of amending. Sorted worst class first, biggest money first."
        flush>
    {{-- A real grid: header filters, an export, saved column widths and a
         footer total somebody takes to a branch meeting. The branch selector is
         off — a run is about one site and the scope came with it. --}}
    <x-data-grid :grid="$grid" />
</x-card>
