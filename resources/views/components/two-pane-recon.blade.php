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

    A CASHIER COLUMN WHERE THE DEPOSIT HAS ONE (27 Sep 2026). A cash bag
    belongs to the cashier who dropped it, and GetSides returns CashierCode and
    CashierName on CashBags rows only. The column follows the SHAPE of the rows
    rather than the area's name, as the time beside the date follows the value:
    the other four areas' rows carry no such column and render exactly as they
    did. Never a blank cell — a code that did not resolve at the branch shows
    the code and says it is not on the employee list, and a bag with no
    drop-safe row says that. Each deposit row also carries the cashier as data
    (`data-cashier-code`, empty for a bag with no drop-safe row, and
    `data-cashier-name` only when it resolved), so a script can group the
    ticked bags by cashier without reading the text.

    THE DROP-SAFE AMOUNT BESIDE THE BAG'S (27 Sep 2026). Amount is what the
    bag was banked as (CashBagAmount), and it is what the match and the split
    add up. What was declared at the safe (BRN_DropSafe.Amount) sits beside it,
    and a bag where the two differ carries an "adjusted" chip — Ryan's call of
    9 Sep 2026, so an adjusted bag is seen rather than reconciled away. The
    Second column is always blank on cash bags, so it gives way to this one.
    The row carries it as `data-drop-safe` (empty for no drop-safe row).

    THE SPLIT BY CASHIER (27 Sep 2026). Where the rows carry a cashier, a panel
    above the two sides — hidden until a bag is ticked — groups the ticked bags
    by cashier: bags, banked, drop safe, a share of the variance and what that
    leaves them accountable for. The share is DERIVED pro rata by what each
    banked (Ryan, 9 Sep 2026) and can be changed by hand; the split has to add
    up to the variance or the Match button stays disabled, and the procedure
    refuses it anyway (ALLOCATION_INCOMPLETE). The panel posts one hidden field,
    `allocation`, as JSON; recon-match.js draws the lines and keeps it in step.
    agora.usp_Recon_ManualMatch is the authority on the derived share — the
    script mirrors its arithmetic to draw the default, nothing more.

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
    // Read off the result set's shape: only CashBags rows carry the column,
    // and a result set has one shape, so the first row answers for all of
    // them. No rows, no column — the empty state says there is nothing to
    // attribute.
    $cashier = $mops->isNotEmpty() && property_exists($mops->first(), 'CashierCode');
@endphp

@if ($cashier)
    {{-- The split by cashier. Hidden until a bag is ticked; recon-match.js
         draws one line per cashier among the ticked bags and keeps the hidden
         field below in step with what is on screen. It lives inside the
         page's form like everything else here, so it posts with the match. --}}
    <div data-match-allocation hidden>
        <input type="hidden" name="allocation" value="" data-alloc-json>

        <div class="twopane" style="grid-template-columns:1fr">
            <div class="twopane-side">
                <div class="twopane-head">
                    <h3>Who the cash is accounted to</h3>
                    <span class="muted" data-alloc-head></span>
                </div>

                <x-table dense id="match-alloc">
                    <x-slot:head>
                        <tr>
                            <th>Cashier</th>
                            <th class="num">Bags</th>
                            <th class="num">Banked</th>
                            <th class="num">Drop safe</th>
                            <th class="num">Share of the variance</th>
                            <th class="num">Accountable</th>
                        </tr>
                    </x-slot:head>
                </x-table>

                <div class="twopane-head" style="border-bottom:0;border-top:1px solid var(--line)">
                    <span data-alloc-state style="font-size:12px"></span>
                    <button type="button" class="btn-ghost" data-alloc-reset hidden>Put back the derived split</button>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="twopane" data-two-pane>
    <div class="twopane-side">
        <div class="twopane-head">
            <h3>Bank statement</h3>
            <span class="muted">{{ \App\Support\Format::n($bank->count()) }}
                {{ Str::plural('line', $bank->count()) }} ·
                {{ \App\Support\Format::r($summary->BankTotal ?? 0) }}</span>
        </div>

        {{-- Sorted and filtered in the browser, over the rows already here.
             Both sides arrive complete from one procedure, so "show me only
             the ones over R10 000" is a question about this page and should
             not cost a round trip. A filtered row's tick box is disabled, so
             the running totals above and the match itself only ever count
             what is on screen. --}}
        <x-table dense :count="$bank->count()" id="match-bank" tools
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

        {{-- `fit` on cash bags only: the cashier and the drop-safe amount
             make it the widest deposit table, and at half a 1440px window the
             Amount column scrolled out of sight. fit pins the numeric columns
             to their content, so the surplus goes to the cashier's name. The
             other areas render as they always have. --}}
        <x-table dense :fit="$cashier" :count="$mops->count()" id="match-mops" tools
                 empty="Nothing on the deposit side is outstanding for this site and period.">
            <x-slot:head>
                <tr>
                    <th class="pick"><input type="checkbox" data-pane-all="mops" aria-label="Select every deposit"></th>
                    <th>Date &amp; time</th>
                    <th>{{ $keyLabel }}</th>
                    @if ($cashier)
                        <th>Cashier</th>
                        <th class="num">Drop safe</th>
                    @else
                        <th>Second</th>
                    @endif
                    <th class="num">Amount</th>
                </tr>
            </x-slot:head>

            @foreach ($mops as $row)
                <tr class="{{ $row->ColourIndex > 0 ? 'pk pk-'.((($row->ColourIndex - 1) % $palette) + 1) : '' }}"
                    data-pane-row="mops" data-pair-key="{{ $row->PairKey }}"
                    data-amount="{{ $row->Amount }}"
                    @if ($cashier)
                        data-cashier-code="{{ $row->CashierCode }}"
                        @if (! blank($row->CashierName)) data-cashier-name="{{ $row->CashierName }}" @endif
                        data-drop-safe="{{ $row->DropSafeAmount ?? '' }}"
                    @endif>
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
                    {{-- Date, and the TIME beside it where the source has one.
                         Smart ATM deposits carry a real device timestamp and
                         the customer reconciles by it — "please can you add the
                         deposit date & time" (ZP, 16 Sep 2026). The other areas
                         file by date alone and sit at midnight, so the test is
                         on the value rather than on the area: no area needs a
                         branch here, and one that gains a time later shows it
                         without this file changing. --}}
                    <td class="mono">
                        {{ substr((string) $row->SourceDate, 0, 10) }}
                        @if (substr((string) $row->SourceDate, 11, 5) !== '' && substr((string) $row->SourceDate, 11, 5) !== '00:00')
                            <span class="muted" style="font-size:11px">{{ substr((string) $row->SourceDate, 11, 5) }}</span>
                        @endif
                    </td>
                    <td>
                        <x-chip :tone="$row->ColourIndex > 0 ? 'good' : 'neutral'">{{ $row->SourceKey }}</x-chip>
                    </td>
                    @unless ($cashier)
                        <td class="mono" style="font-size:11px">{{ $row->SourceRef2 ?? '—' }}</td>
                    @endunless
                    @if ($cashier)
                        {{-- data-sort is what the column's filter lists and
                             sorts on, so "one cashier's bags" is a tick in
                             the filter rather than a search through names. --}}
                        @if (blank($row->CashierCode))
                            <td data-sort="No drop-safe row">
                                <span class="muted" style="font-size:11px"
                                      title="This bag has no BRN_DropSafe row, so nothing records who dropped it">no drop-safe row</span>
                            </td>
                        @elseif (blank($row->CashierName))
                            <td data-sort="{{ $row->CashierCode }} · not on the employee list">
                                <span class="mono">{{ $row->CashierCode }}</span>
                                <x-chip tone="warn" :dot="false"
                                        title="The drop safe names this code, and BRN_Employee has no one by it at this site">not on the employee list</x-chip>
                            </td>
                        @else
                            <td data-sort="{{ $row->CashierName }} · {{ $row->CashierCode }}">
                                {{ $row->CashierName }}
                                <span class="mono muted" style="font-size:11px">{{ $row->CashierCode }}</span>
                            </td>
                        @endif

                        {{-- What was declared at the safe, beside what was
                             banked. Compared in cents: both arrive as money
                             strings with four places. --}}
                        @if ($row->DropSafeAmount === null)
                            <td class="num"><span class="muted" style="font-size:11px">—</span></td>
                        @elseif ((int) round((float) $row->DropSafeAmount * 100) !== (int) round((float) $row->Amount * 100))
                            {{-- The chip on a line of its own under the
                                 figure: beside it, it widened the column
                                 enough to push Amount out of a half-width
                                 pane, for the one bag in fifteen it marks. --}}
                            <td class="num" data-sort="{{ $row->DropSafeAmount }}">
                                {{ \App\Support\Format::r($row->DropSafeAmount) }}
                                <div><x-chip tone="warn" :dot="false"
                                        title="Declared {{ \App\Support\Format::r($row->DropSafeAmount) }} at the drop safe; the bag was banked as {{ \App\Support\Format::r($row->Amount) }}">adjusted</x-chip></div>
                            </td>
                        @else
                            <td class="num" data-sort="{{ $row->DropSafeAmount }}">{{ \App\Support\Format::r($row->DropSafeAmount) }}</td>
                        @endif
                    @endif
                    <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                </tr>
            @endforeach
        </x-table>
    </div>
</div>
