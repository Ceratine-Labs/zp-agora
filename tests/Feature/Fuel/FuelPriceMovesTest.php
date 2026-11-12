<?php

namespace Tests\Feature\Fuel;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * agora.usp_Fuel_GetPriceMoves over the TEST fixture (branch 999, grade 99991):
 * source dates 04 Aug {12.00}, 05 Aug {13.00}, 13 Aug {13.00, 13.10 with no
 * cost or margin}. Every insert is rolled back; nothing reaches a customer.
 */
class FuelPriceMovesTest extends TestCase
{
    use FuelFixture;

    public function test_a_move_is_a_new_price_and_a_mid_day_change_is_flagged_not_split(): void
    {
        [$summary, $moves] = $this->moves();

        $this->assertSame(['2026-08-05', '2026-08-13'], $moves->map(fn (object $m) => substr((string) $m->MoveDate, 0, 10))->all());

        $first = $moves[0];
        $this->assertSame(['12.000000', '12.000000', '13.000000', '13.000000'], [$first->FromLow, $first->FromHigh, $first->ToLow, $first->ToHigh]);
        $this->assertSame('1.000000', $first->Change);
        $this->assertSame('11.123456', $first->ToCostLow);
        $this->assertSame('0', (string) $first->BothRecorded);

        $second = $moves[1];
        $this->assertSame(['13.000000', '13.100000'], [$second->FromLow, $second->ToLow]);
        $this->assertSame('.100000', $second->Change);
        $this->assertSame('1', (string) $second->BothRecorded);
        $this->assertNull($second->ToCostLow, 'A missing cost stays missing; nothing is filled from the DOE matrix.');
        $this->assertNull($second->ToMarginLow);
        $this->assertSame('1', (string) $second->ToMissingCost);

        $this->assertCount(1, $summary);
        $line = $summary[0];
        $this->assertSame('TEST-Fuel price', $line->FuelTypeDescription);
        $this->assertSame(['3', '2'], [(string) $line->DaysPriced, (string) $line->Moves]);
        $this->assertSame(['12.000000', '13.000000', '13.100000'], [$line->OpeningLow, $line->ClosingLow, $line->ClosingHigh]);
        $this->assertNull($line->Change, 'A closing day with two prices has no single change to report.');
    }

    public function test_the_day_after_a_mid_day_change_is_not_a_second_move(): void
    {
        $this->observe('2026-08-14', '13.100000');

        [$summary, $moves] = $this->moves();

        $this->assertCount(2, $moves);
        $this->assertSame('1.100000', $summary[0]->Change);
        $this->assertSame('13.100000', $summary[0]->ClosingHigh);
    }

    public function test_the_first_day_is_compared_with_the_last_priced_day_before_the_selection(): void
    {
        [$summary, $moves] = $this->moves(['DateFrom' => '2026-08-05']);

        $this->assertSame('2026-08-05', substr((string) $moves[0]->MoveDate, 0, 10));
        $this->assertSame('2026-08-04', substr((string) $moves[0]->PreviousDate, 0, 10));
        $this->assertSame('2', (string) $summary[0]->DaysPriced);
        $this->assertSame('13.000000', $summary[0]->OpeningLow);
    }

    public function test_a_selection_outside_the_allowed_sites_returns_nothing_rather_than_more(): void
    {
        foreach (['998', '-1', 'invalid'] as $selection) {
            [$summary, $moves] = $this->moves(['BranchIds' => $selection, 'AllowedBranchIds' => '999']);
            $this->assertCount(0, $summary, "selection {$selection}");
            $this->assertCount(0, $moves, "selection {$selection}");
        }

        [$summary] = $this->moves(['BranchIds' => null, 'AllowedBranchIds' => '999']);
        $this->assertSame([999], $summary->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->unique()->values()->all());
    }

    public function test_an_unordered_range_is_refused(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->moves(['DateFrom' => '2026-08-31', 'DateTo' => '2026-08-01']);
    }

    private function observe(string $date, string $sell): void
    {
        DB::table('PumpIT.dbo.BRN_FuelPrice')->insert([
            'SSBranchId' => 999, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Fuel price',
            'Date' => $date, 'S_Date' => $date, 'SellingPrice' => $sell,
            'CostPrice' => null, 'Margin' => null, 'IsFleetCard' => false,
            'POSType' => 'TEST-POS', 'POSIdentifier' => 'TEST-Fuel', 'EOD_CNTR' => 'TEST', 'HandlingFee' => '0.0100',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: Collection<int, object>, 1: Collection<int, object>}
     */
    private function moves(array $parameters = []): array
    {
        $sets = app(ProcedureService::class)->callSets('usp_Fuel_GetPriceMoves', $parameters + [
            'BranchIds' => '999', 'DateFrom' => '2026-08-01', 'DateTo' => '2026-08-31', 'FuelTypeNo' => 99991,
        ]);

        return [$sets[0] ?? collect(), $sets[1] ?? collect()];
    }
}
