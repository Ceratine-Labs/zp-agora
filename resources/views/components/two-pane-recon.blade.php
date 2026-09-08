{{--
    The reconcile workbench: everything outstanding on the bank at the left,
    everything undeclared on the deposit side at the right, and a person
    pairing them by hand.

    THE COLOUR IS THE FEATURE, and it is earned rather than decorative. A row
    is coloured only where the reference it resolves to appears on BOTH sides,
    so a colour means "there is something over there carrying this same
    reference" — a fact worth looking at. Colouring every row by its own key
    would mean nothing and would cost the reader the same attention.

    NEVER COLOUR ALONE. The key travels beside it as text in a chip, because a
    colour cannot be read out, searched for, or seen by everybody. The colour
    is the thing that makes the pair findable in two hundred rows; the chip is
    the thing that says what it is.

    Ordering follows the colour: paired references first, in colour order, so
    the two sides line up beside each other on first paint and the work is
    already half done before anybody scrolls.

    Both sides live inside ONE form, which the page owns — the totals bar, the
    reason field and the Match button all belong to the same submission, and a
    component that opened its own form would put them in two.

    Props:
      bank     rows from agora.usp_Recon_GetSides, result set 1
      mops     the same procedure's result set 2
      summary  its result set 3, or null before a site has been chosen
      keyLabel what this area calls its reference ("Batch", "Bag", "Slip")
--}}
@props([
    'bank' => null,
    'mops' => null,
    'summary' => null,
    'keyLabel' => 'Reference',
])

@php
    $bank = collect($bank);
    $mops = collect($mops);
    // The palette cycles; the procedure's ColourIndex is a dense rank, so a
    // long answer wraps rather than running out of colours. Wrapping is
    // honest — two distant groups sharing a hue is a smaller problem than a
    // group with no hue at all — and the chip still says which is which.
    $palette = 10;
@endphp

<div class="twopane" data-two-pane>
    <div class="twopane-side">
        <div class="twopane-head">
            <h3>Bank statement</h3>
            <span class="muted">{{ \App\Support\Format::n($bank->count()) }}
                {{ Str::plural('line', $bank->count()) }} ·
                {{ \App\Support\Format::r($summary->BankTotal ?? 0) }}</span>
        </div>

        <x-table dense :count="$bank->count()"
                 empty="Nothing on the statement is outstanding for this site and period.">
            <x-slot:head>
                <tr>
                    <th class="pick"><input type="checkbox" data-pane-all="bank" aria-label="Select every bank line"></th>
                    <th>Date</th>
                    <th>Narrative</th>
                    <th>{{ $keyLabel }}</th>
                    <th class="num">Amount</th>
                </tr>
            </x-slot:head>

            @foreach ($bank as $row)
                <tr class="{{ $row->ColourIndex > 0 ? 'pk pk-'.((($row->ColourIndex - 1) % $palette) + 1) : '' }}"
                    data-pane-row="bank" data-pair-key="{{ $row->PairKey }}"
                    data-amount="{{ $row->Amount }}">
                    <td class="pick">
                        <input type="checkbox" name="bank[]" value="{{ $row->BankStatementLineID }}"
                               data-pane-pick="bank"
                               aria-label="Bank line {{ $row->BankStatementLineID }}">
                    </td>
                    <td class="mono">{{ substr((string) $row->LineDate, 0, 10) }}</td>
                    <td class="mono" style="font-size:11px">{{ Str::limit((string) $row->Description, 52) }}</td>
                    <td>
                        @if ($row->PairKey)
                            <x-chip :tone="$row->ColourIndex > 0 ? 'good' : 'neutral'">{{ $row->PairKey }}</x-chip>
                        @else
                            {{-- No rule reached this line. It is exactly the row
                                 this screen exists for, so it is named rather
                                 than left blank. --}}
                            <span class="muted" style="font-size:11px">no rule</span>
                        @endif
                    </td>
                    <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                </tr>
            @endforeach
        </x-table>
    </div>

    <div class="twopane-side">
        <div class="twopane-head">
            <h3>Deposits</h3>
            <span class="muted">{{ \App\Support\Format::n($mops->count()) }}
                {{ Str::plural('row', $mops->count()) }} ·
                {{ \App\Support\Format::r($summary->MopsTotal ?? 0) }}</span>
        </div>

        <x-table dense :count="$mops->count()"
                 empty="Nothing on the deposit side is outstanding for this site and period.">
            <x-slot:head>
                <tr>
                    <th class="pick"><input type="checkbox" data-pane-all="mops" aria-label="Select every deposit"></th>
                    <th>Date</th>
                    <th>{{ $keyLabel }}</th>
                    <th>Second</th>
                    <th class="num">Amount</th>
                </tr>
            </x-slot:head>

            @foreach ($mops as $row)
                <tr class="{{ $row->ColourIndex > 0 ? 'pk pk-'.((($row->ColourIndex - 1) % $palette) + 1) : '' }}"
                    data-pane-row="mops" data-pair-key="{{ $row->PairKey }}"
                    data-amount="{{ $row->Amount }}">
                    <td class="pick">
                        {{-- The deposit family mostly has no id of its own, so
                             the row is named by the triple that identifies it,
                             packed into one field: a half-submitted row cannot
                             become a different row. --}}
                        <input type="checkbox" name="mops[]" data-pane-pick="mops"
                               value="{{ json_encode([
                                   'id' => $row->SourceId,
                                   'key' => $row->SourceKey,
                                   'dt' => substr((string) $row->SourceDate, 0, 10),
                                   'amt' => (float) $row->Amount,
                               ]) }}"
                               aria-label="Deposit {{ $row->SourceKey }}">
                    </td>
                    <td class="mono">{{ substr((string) $row->SourceDate, 0, 10) }}</td>
                    <td>
                        <x-chip :tone="$row->ColourIndex > 0 ? 'good' : 'neutral'">{{ $row->SourceKey }}</x-chip>
                    </td>
                    <td class="mono" style="font-size:11px">{{ $row->SourceRef2 ?? '—' }}</td>
                    <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                </tr>
            @endforeach
        </x-table>
    </div>
</div>
