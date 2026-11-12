<?php

namespace Tests\Feature\Grid;

use App\Grid\GridColumn;
use App\Grid\StatusTone;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Status word to chip tone table.
 *
 * It is a keyword match shared by every grid in the application, which makes
 * it exactly the kind of thing that goes wrong quietly: a new phrase added for
 * one screen changes the colour on another, and nothing errors.
 *
 * That happened while writing it. Adding `active` for the critical list would
 * have turned `Inactive` — which usp_Core_GridUsers emits for somebody who can
 * no longer sign in — bright green on the users screen, because "Inactive"
 * contains "active". The fix was an exact match; this file is so the next
 * person to add a keyword finds out the same way, in a second, rather than in
 * a screenshot.
 *
 * Every phrase below is one a shipped procedure actually emits.
 */
class StatusToneTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function statuses(): array
    {
        return [
            // The critical list. Out of stock is the emergency the screen
            // exists for and must not wear the same chip as In stock.
            'out of stock is critical' => ['Out of stock', 'crit'],
            'low is a warning' => ['Low', 'warn'],
            'in stock is good' => ['In stock', 'good'],
            'no POS record is serious' => ['No POS record', 'serious'],
            'off the list is neither' => ['Off the list', 'neutral'],

            // The stock master.
            'never counted is serious' => ['Never counted', 'serious'],
            'quiet for 90 days is a warning' => ['Not counted in 90 days', 'warn'],
            'retired is neither' => ['Retired', 'neutral'],
            'below cost is critical' => ['Below cost', 'crit'],
            'no cost price is serious' => ['No cost price', 'serious'],
            'no POS sell is serious' => ['No POS sell', 'serious'],

            // Existing report phrases keep the same tones after sharing the table.
            'not started is critical' => ['Not started', 'crit'],
            'bank disagreement is serious' => ['Bank does not agree', 'serious'],
            'open is a warning' => ['Still open', 'warn'],
            'balanced is good' => ['Balanced', 'good'],
            'uppercase stock phrase is critical' => ['OUT OF STOCK', 'crit'],

            // The users screen, which shares this table and must not move.
            'active is good' => ['Active', 'good'],
            'INACTIVE IS NOT GOOD' => ['Inactive', 'neutral'],
            'locked is neither' => ['Locked', 'neutral'],

            // A phrase nothing knows falls to neutral rather than to a fatal.
            'an unknown phrase is neutral' => ['Something nobody has said before', 'neutral'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_a_status_gets_the_tone_it_deserves(string $status, string $expected): void
    {
        $this->assertSame($expected, StatusTone::of($status));
    }

    public function test_the_match_is_case_insensitive(): void
    {
        $this->assertSame('crit', StatusTone::of('OUT OF STOCK'));
        $this->assertSame('crit', StatusTone::of('out of stock'));
    }

    #[DataProvider('statuses')]
    public function test_report_and_grid_status_cells_render_the_same_tone(string $status, string $expected): void
    {
        $report = $this->reportCell($status);

        $this->assertStringContainsString('class="chip '.$expected.' tone-'.$expected.'"', $report);
        $this->assertStringContainsString(e($status), $report);
        $this->assertSame($this->gridCell($status), $report);
    }

    public function test_blank_status_cells_remain_a_muted_dash_without_a_chip(): void
    {
        foreach ([null, ''] as $blank) {
            $report = $this->reportCell($blank);

            $this->assertSame('<span class="muted">—</span>', $report);
            $this->assertSame($this->gridCell($blank), $report);
        }
    }

    private function reportCell(?string $status): string
    {
        return $this->normalize(Blade::render('<x-reports::cell type="chip" :value="$status" />', ['status' => $status]));
    }

    private function gridCell(?string $status): string
    {
        return $this->normalize(view('grid._cell', [
            'column' => new GridColumn(key: 'Status', label: 'Status', format: 'chip'),
            'row' => (object) ['Status' => $status],
        ])->render());
    }

    private function normalize(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }
}
