<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\SettingCatalogue;

/**
 * System defaults and thresholds (T026): the group values, then PumpIT's.
 *
 * 1. Every setting in Modules\Core\Support\SettingCatalogue becomes a row on
 *    the group entity (BranchId = config('agora.group_branch_id')), its value
 *    the module config literal it was read from until now — so the first
 *    read through Settings::for() answers exactly what the screen used to.
 *    A key already in the table keeps its value: only its definition
 *    (type, description, bounds, whether a site may override, what reads it)
 *    is brought up to date.
 * 2. agora.usp_Migrate_Setting copies PumpIT's SS_SysDefaults and BRN_Config
 *    (read through agora.vw_Legacy*, never written) as read-only rows.
 *
 * Writes Agora's own agora.Setting and agora.MasterChange only. After the
 * branch master (11), because a BRN_Config row lands on its branch.
 */
class SettingSeeder extends Seeder
{
    public int $seedOrder = 12;

    public function run(SettingService $settings): void
    {
        $schema = config('agora.schema');
        $group = $settings->groupBranchId();
        $created = 0;

        foreach (SettingCatalogue::all() as $key => $definition) {
            $fields = [
                'Type' => $definition['type'],
                'Description' => $definition['description'],
                'Choices' => isset($definition['choices']) ? implode('|', $definition['choices']) : null,
                'MinValue' => $definition['min'] ?? null,
                'MaxValue' => $definition['max'] ?? null,
                'IsNullable' => (int) ($definition['nullable'] ?? false),
                'AllowsBranch' => (int) ($definition['branch'] ?? true),
                'UsedBy' => $definition['usedBy'],
            ];

            $exists = DB::table("{$schema}.Setting")->where('BranchId', $group)->where('SettingKey', $key)->exists();
            if ($exists) {
                DB::table("{$schema}.Setting")->where('BranchId', $group)->where('SettingKey', $key)
                    ->update($fields + ['UpdatedAt' => now()]);

                continue;
            }

            DB::table("{$schema}.Setting")->insert($fields + [
                'BranchId' => $group,
                'SettingKey' => $key,
                'Value' => self::normalise($definition['type'], SettingCatalogue::default($key)),
                'Source' => 'seed',
                'CreatedAt' => now(),
            ]);
            $created++;
        }

        $this->command?->info("  Settings: {$created} group values seeded from module config.");
        $this->command?->info('  Settings: '.$settings->migrate()->Message);
    }

    /** As agora.usp_Core_SaveSetting stores it, so a seeded value and a saved one read alike. */
    public static function normalise(string $type, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'int' => (string) (int) $value,
            'money' => number_format((float) $value, 2, '.', ''),
            'litres' => number_format((float) $value, 3, '.', ''),
            'decimal', 'share' => number_format((float) $value, 4, '.', ''),
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
