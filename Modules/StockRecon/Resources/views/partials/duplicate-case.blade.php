{{--
    One duplicate issue, read the way the customer report reads it: what kind
    it is, where it stands, what happened, why it is (or is not) clear, and
    the shifts that prove it — the twin where the issue arrived, the shift as
    keyed and, for a clear one being taken out, the same shift corrected.

    The STATE is the procedure's (agora.usp_StockRecon_GetDuplicates); this
    only words it. The presses are the shared release partial, behind its
    permissions — the same press the chain panel offers.
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $clear = $case->DupTier === 'D1';
    $at = fn ($date, $shift) => Carbon::parse($date)->format('j M').' shift '.$shift;
    $when = fn ($ts) => $ts ? Carbon::parse($ts)->format('j M Y, H:i') : 'an unrecorded time';
    $who = fn ($name) => $name ?: 'somebody';

    /* Three places always, as everywhere else in stock recon (Ryan, 13 Oct
       2026: "sometimes 1, sometimes 1.000"). */
    $qty = fn ($v) => $v === null ? Format::NOTHING : Format::n($v, 3);

    $here = $at($case->TransactionDate, $case->ShiftNo);
    $twin = $case->TwinDate ? $at($case->TwinDate, $case->TwinShift) : null;
    $issue = $qty($case->IssueKeyed);
    $short = $qty(abs((float) $case->ShortQty));
    $shortValue = Format::r(abs((float) $case->ShortValue));
    $issueValue = Format::r($case->IssueValue);
    $item = Str::squish((string) $case->ItemDescription);

    $twinSays = match ($case->TwinResult) {
        'nil' => $twin.' balances: that issue arrived and was counted.',
        'short' => $twin.' is short by '.$qty(abs((float) $case->TwinVar)).' as well.',
        'over' => $twin.' is over by '.$qty($case->TwinVar).'.',
        default => null,
    };
    $outside = $case->TwinLineId === null && $twin ? ' ('.$twin.' is outside this run\'s window.)' : '';

    [$stateLabel, $stateTone] = match ($case->State) {
        'will_remove' => ['Will be set to 0', 'good'],
        'removed' => [$case->AmendmentStampMode === 'live' ? 'Set to 0' : 'Recorded as 0', 'good'],
        'reversed' => ['Reversed', 'neutral'],
        'skipped' => ['Skipped at the commit', 'warn'],
        'left_out' => ['Not written', 'warn'],
        'not_removed' => ['Not corrected', 'warn'],
        'held' => ['Holding the chain back', 'serious'],
        'released' => ['Released', 'neutral'],
        'released_since' => ['Released since this preview', 'neutral'],
        'withdrawn' => ['Release withdrawn', 'warn'],
        default => ['Listed', 'neutral'],
    };

    if ($clear) {
        $terms = [
            'What happened' => 'The issue of '.$issue.' on '.$here.' was keyed twice. The shift\'s count moved only by '
                .'what the till sold, so it is short by exactly the issue; the identical issue on '.$twin
                .' arrived and was counted.'.$outside,
            'Why it is clear' => 'Short by exactly the issue, the identical issue beside it arrived, no other shift in '
                .'the pair is short by it, and it is shelf stock — not prepared food, where a batch thrown away '
                .'looks the same.',
            'Result' => match ($case->State) {
                'will_remove' => ($stampMode === 'live'
                        ? 'The issue is set to 0 in PumpIT when this run is committed'
                        : 'The issue is recorded as 0 in Agora when this run is committed (journal mode: PumpIT does not change)')
                    .' — '.$issueValue.' not charged.',
                'removed' => ($case->AmendmentStampMode === 'live'
                        ? 'Set to 0 in PumpIT on '.$when($case->AmendedAt).' by '.$who($case->AmendedBy)
                        : 'Recorded as 0 in Agora on '.$when($case->AmendedAt).' by '.$who($case->AmendedBy).' (journal mode: PumpIT not changed)')
                    .' — '.$issueValue.' not charged. A reversal of this run puts the issue back.',
                'reversed' => 'Set to 0 on '.$when($case->AmendedAt).' by '.$who($case->AmendedBy).', then reversed on '
                    .$when($case->ReversedAt).' by '.$who($case->ReversedBy).': the issue of '.$issue.' is back.',
                'skipped' => 'Not written at the commit: '.$case->BlockReason,
                'left_out' => 'Not written: this run was committed without this shift.',
                default => 'Not corrected: '.(Str::after((string) ($case->DupReason ?: 'Not removed: the run did not take it out'), 'Not removed: '))
                    .'.',
            },
        ];
    } else {
        $terms = [
            'What happened' => 'The same issue of '.$issue.' was keyed on '.$here.' and on '.($twin ?? 'a neighbouring shift')
                .'. '.Str::ucfirst($here).' is short by '.$short.' ('.$shortValue.')'.($twinSays ? '; '.$twinSays : '.').$outside,
            'Why it is not proven' => rtrim((string) ($case->DupReason ?: 'The count cannot say which issue was real'), '.')
                .'. Only the issue book, the storeroom count or the delivery note can say which issue was real.',
            'Result' => match ($case->State) {
                'held' => 'Holding this item\'s chain back: nothing on it is written until somebody who has checked '
                    .'the issue book releases it with a reason. '.$shortValue.' stays on the shift as counted.',
                'released' => 'Released by '.$who($case->ReleasedBy).' on '.$when($case->ReleasedAt).': "'.$case->ReleaseReason
                    .'". It holds nothing back; '.$shortValue.' stays on the shift as counted.',
                'released_since' => 'Released by '.$who($case->ReleasedBy).' on '.$when($case->ReleasedAt).', after this run '
                    .'was made: "'.$case->ReleaseReason.'".'
                    .($case->DupHold ? ' This run still holds the chain back — preview again to let it commit.' : ''),
                'withdrawn' => 'Released when this run was made; '.$who($case->WithdrawnBy).' withdrew the release on '
                    .$when($case->WithdrawnAt).'. The next preview holds the chain back again.',
                default => 'Listed for the site: holding a chain back was switched off when this run was made, so it '
                    .'held nothing back. '.$shortValue.' stays on the shift as counted.',
            },
        ];
    }

    $summary = $item.', '.$here.', issue '.$issue.($twin ? ' — the same issue on '.$twin : '').'.';
    $released = in_array($case->State, ['released', 'released_since'], true);

    /* How each shift reads in the Result column. */
    $result = function (object $s) use ($qty): array {
        return match ($s->Result) {
            'nil' => [$s->Role === 'corrected' ? '✓ nil · not charged' : ($s->Role === 'twin' ? '✓ arrived' : '✓ nil'), 'good'],
            'short' => ['✕ short '.$qty(abs((float) $s->QtyVar)).' · '.Format::r(abs((float) $s->VarValue)), 'crit'],
            'over' => ['over '.$qty($s->QtyVar).' · '.Format::r($s->VarValue), 'warn'],
            default => [Format::NOTHING, 'neutral'],
        };
    };
@endphp

<x-case :tone="$clear ? 'good' : 'serious'"
        :badge="$clear ? '✓ Clear duplicate (D1)' : '◆ Possible duplicate (D2)'"
        :state="$stateLabel" :state-tone="$stateTone"
        :title="collect([$case->BranchName, $case->AreaDescription, $item, Carbon::parse($case->TransactionDate)->format('j M Y')])->filter()->implode(' · ')"
        :meta="'Item '.$case->StockItemNo.($case->SellPrice !== null ? ' · '.Format::r($case->SellPrice).' each' : '').' · issue '.$issue"
        :terms="$terms">
    <x-slot:actions>
        {{-- Every key the partial reads is passed, null where unused: an
             @include inherits this scope, and the exceptions screen has a
             $grid of its own that would otherwise turn a release into "the
             ticked rows of that grid". --}}
        @unless ($clear)
            @if ($released)
                @include('stockrecon::partials.duplicate-release', [
                    'run' => $run, 'withdraw' => (int) $case->ReleaseId, 'summary' => $summary,
                    'label' => 'Withdraw release', 'lines' => null, 'grid' => null,
                ])
            @else
                @include('stockrecon::partials.duplicate-release', [
                    'run' => $run, 'lines' => [(int) $case->LineId], 'summary' => $summary,
                    'label' => 'Release…', 'withdraw' => null, 'grid' => null,
                ])
            @endif
        @endunless
        {{-- The whole chain behind the shift (feature-rules §3.7): its
             balancing, every flag, and who was on. --}}
        <a class="btn-ghost btn-row" href="{{ route('app.stockrecon.line', [$run, $case->LineId]) }}">Open the chain</a>
    </x-slot:actions>

    <x-table dense :count="$case->shifts->count()" :procedure="$procedure"
             empty="The run holds none of the shifts behind this case.">
        <x-slot:head>
            <tr>
                <th class="l">Shift</th>
                <th class="num">Open</th>
                <th class="num">+ Issued</th>
                <th class="num">− Sold</th>
                <th class="num">= Expected</th>
                <th class="num">Counted</th>
                <th class="l">Result</th>
            </tr>
        </x-slot:head>

        @foreach ($case->shifts as $s)
            @php([$says, $resultTone] = $result($s))
            <tr @class(['is-matched' => $s->Role === 'corrected'])>
                <td class="l">
                    {{ $at($s->TransactionDate, $s->ShiftNo) }}{{ match ($s->Role) { 'keyed' => ', as keyed', 'corrected' => ', corrected', default => '' } }}
                    <br><span class="muted" style="font-size:12px">{{ match ($s->Role) {
                        'twin' => $s->OnRun ? 'the identical issue' : 'the identical issue, outside this run',
                        'keyed' => $clear ? 'the issue keyed again' : 'this shift',
                        default => 'the issue at 0',
                    } }}</span>
                </td>
                <td class="num">
                    {{ $qty($s->QtyOpen) }}
                    {{-- The opening the duplicate was judged on is the previous
                         closing; where the count missed it, say so. --}}
                    @if ($s->OpenCounted !== null && abs((float) $s->OpenCounted - (float) $s->QtyOpen) > 0.0005)
                        <br><span class="muted" style="font-size:11px" title="The opening is the previous closing; the count read {{ $qty($s->OpenCounted) }}">counted {{ $qty($s->OpenCounted) }}</span>
                    @endif
                </td>
                <td class="num">
                    @if ($s->Role === 'corrected')
                        <x-reports::before-after :before="$s->IssuedBefore" :after="$s->QtyIssued" :dp="3" />
                    @else
                        {{ $qty($s->QtyIssued) }}
                    @endif
                </td>
                <td class="num">{{ $qty($s->QtyPOS) }}</td>
                <td class="num">{{ $qty($s->QtyExpected) }}</td>
                <td class="num">{{ $qty($s->QtyClose) }}</td>
                <td class="l"><x-chip :tone="$resultTone" :dot="false">{{ $says }}</x-chip></td>
            </tr>
        @endforeach
    </x-table>
</x-case>
