<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The staff-short codes (T040), and how the legacy's free-text reasons read as
 * them.
 *
 * THE FIVE CODES: the plan's four — pump short, cashier short, stock loss,
 * other — and driver drinks / promotion, added by Ryan (PM decision 01a0e5b5,
 * 28 Sep 2026) because it is most of what the legacy calls a staff short. A
 * short raised in Agora carries one, and its Label is what is written into
 * BRN_StaffShorts.Reason, so PumpIT's own screens read one of five labels.
 * 'other' needs a note — it says nothing on its own.
 *
 * THE ALIASES. BRN_StaffShorts.Reason is free text: 2,619 different reasons for
 * 4,851 shorts on the customer's instance (27 Sep 2026). Each alias is a LIKE
 * pattern; agora.vw_CashStaffShort gives a legacy short the code of the first
 * one its reason matches (lowest Priority), else the first its note matches,
 * else the first its person matches (MatchOn 'employee'), else 'other' — and
 * keeps the text as typed as the note. Nothing in PumpIT is rewritten.
 * Measured against every legacy short on 28 Sep 2026 (reason / note / person):
 *
 *   driver_drinks  4,297 shorts — 3,569 by the reason ("Driver Drinks",
 *                  "Drivers Drinks", "Diesel Promo", "Coke Promo", "DRIVER
 *                  MEAL", "Dribver Drinks" …), 698 by the note (a bus company
 *                  as the reason, "drivers drink" in the note), 30 by the person
 *                  alone — the "Driver Drinks" pseudo-employee D001
 *   pump_short       182 shorts — 100 by 47 reason spellings ("pump short" (49),
 *                  "Pumpshort", "punp short", "PUMP SHORT 07.07.2024", "Palesa
 *                  23.07.2024 Keletso Pump Short" …), 82 by the note
 *   stock_loss        25 shorts — "Stock short", "short lubes", "oil short",
 *                  "SHORT ON ICE" …
 *   cashier_short      5 shorts — "Till Short between 2 Z-Reads", "Replace of
 *                  shorts at Till Mbali" …
 *   other            342 shorts — drive-offs, customers who did not pay, names
 *                  and dates, corrections: the text is kept as the note.
 *
 * Upserts on the natural key (BranchId, Code) and (BranchId, Pattern), so a
 * rerun after --forget changes nothing a person has not changed — and puts back
 * a code somebody soft-deleted only by clearing DeletedAt, which is the point
 * of seeding it.
 */
class StaffShortReasonSeeder extends Seeder
{
    public int $seedOrder = 60;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: bool, 4: int}> code, label, description, needs a note, sort */
    private const CODES = [
        ['pump_short', 'Pump short', 'A pump attendant handed in less than the pumps sold on their shift. What feeds the cashup\'s pump shortages.', false, 10],
        ['cashier_short', 'Cashier short', 'A cashier is answerable for money missing from their till or their bags — including a cash-bag match whose bank deposit came in under the bags.', false, 20],
        ['stock_loss', 'Stock loss', 'Stock a person is answerable for that was not paid for — a short counted in the shop, lubes, ice.', false, 30],
        ['driver_drinks', 'Driver drinks / promotion', 'A drink or meal given to a driver, or stock given away on a promotion (diesel, cooldrink), charged so the cashup balances.', false, 40],
        ['other', 'Other', 'Anything else. Say what in the note.', true, 90],
    ];

    /** @var array<int, array{0: string, 1: string, 2: int, 3: string, 4?: string}> pattern, code, priority, why, match on */
    private const ALIASES = [
        ['%pump%short%', 'pump_short', 10, 'pump short, Pumpshort, Pump Short 03.04.2024, … Pump Short (46 spellings)'],
        ['%punp%short%', 'pump_short', 11, 'punp short'],
        ['%cashier%short%', 'cashier_short', 20, 'cashier short'],
        ['%till%short%', 'cashier_short', 21, 'Till Short between 2 Z-Reads'],
        ['%short%at%till%', 'cashier_short', 22, 'Replace of shorts at Till …'],
        ['%stock%short%', 'stock_loss', 30, 'Stock short, Stock Shorts Dec and Jan'],
        ['%stock%loss%', 'stock_loss', 31, 'stock loss'],
        ['%short%lube%', 'stock_loss', 32, 'short lubes'],
        ['%oil%short%', 'stock_loss', 33, 'oil short'],
        ['short on %', 'stock_loss', 34, 'SHORT ON ICE, SHORT ON SUNGLASSES'],
        ['%driver%', 'driver_drinks', 40, 'Driver Drinks, Drivers Drinks, DRIVER MEAL, Bus Driver …'],
        ['%drink%', 'driver_drinks', 41, 'Diver drinks, Driever drinks, Cold Drink …'],
        ['%meal%', 'driver_drinks', 42, 'Bus drivver and guide meal'],
        ['%promo%', 'driver_drinks', 43, 'Diesel Promo, Coke Promo, Cooldrink Promo'],
        ['%driver%', 'driver_drinks', 49, 'The "Driver Drinks" pseudo-employee (D001 at four sites) — only where neither the reason nor the note matched', 'employee'],
    ];

    public function run(): void
    {
        $db = DB::connection(config('agora.connections.app'));
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $now = now();

        foreach (self::CODES as [$code, $label, $description, $needsNote, $sort]) {
            $key = ['BranchId' => $branchId, 'Code' => $code];
            $values = [
                'Label' => $label, 'Description' => $description, 'NeedsNote' => $needsNote,
                'IsActive' => true, 'SortOrder' => $sort, 'DeletedAt' => null, 'DeletedBy' => null, 'UpdatedAt' => $now,
            ];

            $db->table("{$schema}.StaffShortReason")->where($key)->exists()
                ? $db->table("{$schema}.StaffShortReason")->where($key)->update($values)
                : $db->table("{$schema}.StaffShortReason")->insert($key + $values + ['CreatedAt' => $now]);
        }

        foreach (self::ALIASES as $alias) {
            [$pattern, $code, $priority, $why] = $alias;
            $key = ['BranchId' => $branchId, 'MatchOn' => $alias[4] ?? 'text', 'Pattern' => $pattern];
            $values = [
                'ReasonCode' => $code, 'Priority' => $priority, 'Description' => $why,
                'IsActive' => true, 'DeletedAt' => null, 'DeletedBy' => null, 'UpdatedAt' => $now,
            ];

            $db->table("{$schema}.StaffShortReasonAlias")->where($key)->exists()
                ? $db->table("{$schema}.StaffShortReasonAlias")->where($key)->update($values)
                : $db->table("{$schema}.StaffShortReasonAlias")->insert($key + $values + ['CreatedAt' => $now]);
        }

        $this->command?->info('  Cash: '.count(self::CODES).' staff-short codes and '.count(self::ALIASES).' legacy spellings.');
    }
}
