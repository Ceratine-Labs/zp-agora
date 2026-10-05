<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The prepared-food flag per counting area, as PROPOSED — for head office to
 * confirm.
 *
 * Rule 5 of the duplicate-issue test (1 Oct 2026): in prepared food a batch
 * thrown away unrecorded looks exactly like an issue keyed twice, so a food
 * area is never a clear duplicate (D1), only a possible one (D2). PumpIT's own
 * pre-production flags are 0 on all 7,450 items, so the flag is Agora's. It is
 * proposed from the customer's area group (Food Items, QSR Items, Butchery
 * Items) and from the area's name — hot food, bakery, kitchen, grab & go,
 * butchery, restaurant, a QSR's BOH — by agora.vw_StockAreaFood, which is the
 * ONE place that proposal is written (v1__14h). This copies it into
 * agora.StockAreaSetting, unconfirmed, so head office has a row per area to
 * confirm or overturn.
 *
 * An area that appears later needs no row: the view proposes for it all the
 * same. Inserts only; a row that exists — confirmed or not — is never touched,
 * so re-seeding cannot undo head office's call.
 */
class AreaFoodSeeder extends Seeder
{
    public int $seedOrder = 70;

    public function run(): void
    {
        $schema = config('agora.schema');

        DB::connection(config('agora.connections.app'))->statement("
            INSERT INTO [{$schema}].[StockAreaSetting] (BranchId, AreaNo, IsPreparedFood, FoodBasis, CreatedAt)
            SELECT f.BranchId, f.AreaNo, f.ProposedPreparedFood, f.FoodBasis, SYSDATETIME()
            FROM [{$schema}].[vw_StockAreaFood] f
            WHERE NOT EXISTS (SELECT 1 FROM [{$schema}].[StockAreaSetting] s
                              WHERE s.BranchId = f.BranchId AND s.AreaNo = f.AreaNo);
        ");
    }
}
