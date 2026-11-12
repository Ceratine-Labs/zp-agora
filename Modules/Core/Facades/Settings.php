<?php

namespace Modules\Core\Facades;

use Illuminate\Support\Facades\Facade;
use Modules\Core\Services\SettingService;

/**
 * System defaults and thresholds (T026).
 *
 *     Settings::for($branchId, 'cash.dropsafe.reason_threshold')   // 2000.0
 *     Settings::for(null, 'cash.deductions.cash_leniency')          // the estate's value
 *     Settings::prefixed($branchId, 'utilities.tariff.')            // ['B' => null, …]
 *
 * The site's own value, else the group entity's, else the code default in
 * Modules\Core\Support\SettingCatalogue. Every threshold a module reads goes
 * through here — tests/Feature/Core/SettingsTest holds the line.
 *
 * @method static mixed for(?int $branchId, string $key)
 * @method static array<string, mixed> prefixed(?int $branchId, string $prefix)
 * @method static int groupBranchId()
 * @method static void forget()
 * @method static void fake(array<string, mixed> $values = [])
 *
 * @see SettingService
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingService::class;
    }
}
