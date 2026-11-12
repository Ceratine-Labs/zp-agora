@php
    use App\Support\Format;

    // A day can carry more than one recorded price; it is shown as the range,
    // never collapsed to one of them.
    $range = fn ($low, $high, int $dp = 2): string => $low === null ? Format::n(null)
        : ((string) $low === (string) $high ? Format::n($low, $dp) : Format::n($low, $dp).' – '.Format::n($high, $dp));
    $grade = fn (object $row): string => ($row->FuelTypeDescription ?? 'Grade '.$row->FuelTypeNo).' ('.$row->FuelTypeNo.')'.((bool) $row->IsFleetCard ? ' · fleet' : '');
    $summary = $pump['summary'];
    $moves = $pump['moves'];
@endphp

@if ($pumpMessage)
    <x-notice tone="warn" title="The pump prices could not be read">{{ $pumpMessage }}</x-notice>
@else
    <x-card title="Pump prices by grade" collapsible open remember="fuel.pump.summary"
        :sub="'What each site charged per litre in this selection, from BRN_FuelPrice. '.Format::n($summary->count()).' site and grade '.($summary->count() === 1 ? 'line' : 'lines').'.'">
        <x-table layout-key="fuel.matrix.pump-summary" procedure="agora.usp_Fuel_GetPriceMoves" :count="$summary->count()" dense fit
            empty="No pump prices recorded for this selection." data-fuel-table="summary">
            <x-slot:head><tr><th>Site</th><th>Grade</th><th class="num">Days priced</th><th class="num">Opening / ℓ</th><th class="num">Closing / ℓ</th><th class="num">Low</th><th class="num">High</th><th class="num">Moves</th><th class="num">Change</th></tr></x-slot:head>
            @foreach ($summary as $line)
                <tr>
                    <td class="fit-min">{{ $line->BranchName }}</td>
                    <td><b>{{ $grade($line) }}</b></td>
                    <td class="num">{{ Format::n($line->DaysPriced) }}</td>
                    <td class="num">{{ $range($line->OpeningLow, $line->OpeningHigh) }}</td>
                    <td class="num"><b>{{ $range($line->ClosingLow, $line->ClosingHigh) }}</b></td>
                    <td class="num">{{ Format::n($line->SellLow, 2) }}</td>
                    <td class="num">{{ Format::n($line->SellHigh, 2) }}</td>
                    <td class="num">{{ Format::n($line->Moves) }}</td>
                    <td class="num"><x-delta :value="$line->Change" suffix="" :dp="2" /></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="Price-change log"
        :sub="'Every pump price move in this selection, by source date. '.Format::n($moves->count()).' '.($moves->count() === 1 ? 'move' : 'moves').'.'">
        <x-slot:info>
            <p>A move is a source date on which a site recorded a price for a grade that it did not record on its previous priced day. From is that previous day's price; To is the new one.</p>
            <p>When the old and new prices were both recorded on the same day (a change during the day), the day is marked rather than split, and the following day is not counted again. A missing cost or margin stays blank: nothing is taken from the DOE matrix.</p>
        </x-slot:info>
        <x-table layout-key="fuel.matrix.pump-moves" procedure="agora.usp_Fuel_GetPriceMoves" :count="$moves->count()" dense fit tools
            data-tt-page="50" empty="No pump price moved in this selection." data-fuel-table="moves">
            <x-slot:head><tr><th class="fit-min">Source date</th><th>Site</th><th>Grade</th><th class="num">From / ℓ</th><th class="num">To / ℓ</th><th class="num">Change</th><th class="num">New cost / ℓ</th><th class="num">New margin / ℓ</th><th>Notes</th></tr></x-slot:head>
            @foreach ($moves as $move)
                <tr>
                    <td class="fit-min">{{ substr((string) $move->MoveDate, 0, 10) }}</td>
                    <td>{{ $move->BranchName }}</td>
                    <td>{{ $grade($move) }}</td>
                    <td class="num">{{ $range($move->FromLow, $move->FromHigh) }}</td>
                    <td class="num"><b>{{ $range($move->ToLow, $move->ToHigh) }}</b></td>
                    <td class="num"><x-delta :value="$move->Change" suffix="" :dp="2" /></td>
                    <td class="num">{{ $range($move->ToCostLow, $move->ToCostHigh, 4) }}</td>
                    <td class="num">{{ $range($move->ToMarginLow, $move->ToMarginHigh, 4) }}</td>
                    <td>
                        @if ($move->BothRecorded) <x-chip tone="warn">Old and new both recorded this day</x-chip> @endif
                        @if ((int) $move->ToMissingCost > 0) <x-chip tone="neutral">Cost not recorded</x-chip> @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
@endif
