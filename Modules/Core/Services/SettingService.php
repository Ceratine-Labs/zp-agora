<?php

namespace Modules\Core\Services;

use App\Support\Format;
use App\Support\ProcedureService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Support\SettingCatalogue;

/**
 * System defaults and thresholds (T026) — the reads every module makes, and
 * the Setup screen's reads and writes.
 *
 * READS: Settings::for($branchId, 'key') answers, in order,
 *   1. the site's own row in agora.Setting,
 *   2. the group entity's row (config('agora.group_branch_id'), never NULL),
 *   3. the default in Modules\Core\Support\SettingCatalogue — the module
 *      config literal the threshold was read from before T026.
 * A null $branchId, or the group's own id, asks for the estate's value.
 * Values come back typed (int, float, bool, string) or null where a setting
 * may be empty. A key that is neither in the table nor in the catalogue is a
 * typo and throws, rather than quietly answering null.
 *
 * The table is read once per request (this service is bound `scoped`), all
 * of it — it is a few dozen rows, and a screen asks for several. If the table
 * is not there yet (a deploy between rsync and migrate), every read answers
 * the catalogue default, which is exactly what the screen did before.
 *
 * WRITES go through agora.usp_Core_SaveSetting, which owns the rules and the
 * audit (agora.MasterChange). Nothing here writes PumpIT; migrate() reads it
 * through agora.vw_Legacy* inside agora.usp_Migrate_Setting.
 */
class SettingService
{
    /** @var array<string, array<int, array{type: string, value: ?string}>>|null key => branch => row */
    private ?array $rows = null;

    /** Set by fake(): the table is not read, and forget() keeps what was faked. */
    private bool $faked = false;

    public function __construct(private ProcedureService $procedures) {}

    public function groupBranchId(): int
    {
        return (int) config('agora.group_branch_id', 2);
    }

    /** The value of $key at $branchId: the site's own, else the group's, else the code default. */
    public function for(?int $branchId, string $key): mixed
    {
        $rows = $this->rows()[$key] ?? [];
        $group = $this->groupBranchId();

        if ($branchId !== null && $branchId !== $group && array_key_exists($branchId, $rows)) {
            return self::cast($rows[$branchId]['type'], $rows[$branchId]['value']);
        }
        if (array_key_exists($group, $rows)) {
            return self::cast($rows[$group]['type'], $rows[$group]['value']);
        }

        $definition = SettingCatalogue::definition($key);
        if ($definition === null) {
            if ($rows !== []) {
                return null; // a key kept per site only (pumpit.BRN_Config.*), with no row for this one
            }
            throw new InvalidArgumentException("Unknown setting [{$key}]. Declare it in ".SettingCatalogue::class.'.');
        }

        $default = SettingCatalogue::default($key);

        return $default === null ? null : self::cast($definition['type'], (string) $default);
    }

    /**
     * Every setting under a prefix, by what follows it: prefixed($b, 'utilities.tariff.')
     * gives ['B' => 2.95, 'L' => null, …]. The keys are the union of the table's and the
     * catalogue's, each resolved through for().
     *
     * @return array<string, mixed>
     */
    public function prefixed(?int $branchId, string $prefix): array
    {
        $keys = array_merge(array_keys($this->rows()), array_keys(SettingCatalogue::all()));
        $out = [];
        foreach (array_unique($keys) as $key) {
            if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
                $out[substr($key, strlen($prefix))] = $this->for($branchId, $key);
            }
        }
        ksort($out);

        return $out;
    }

    /** Drop the per-request read, so the next for() sees what was just saved. */
    public function forget(): void
    {
        if (! $this->faked) {
            $this->rows = null;
        }
    }

    /**
     * For a test: answer without reading agora.Setting — the given group
     * values, and every other key from its code default, which is the module
     * config literal. So a test that drives a threshold with config([...])
     * keeps doing so: Settings::fake() in setUp.
     *
     * @param  array<string, mixed>  $values  key => group value
     */
    public function fake(array $values = []): void
    {
        $this->faked = true;
        $this->rows = [];
        foreach ($values as $key => $value) {
            $type = SettingCatalogue::definition($key)['type'] ?? 'string';
            $this->rows[$key][$this->groupBranchId()] = ['type' => $type, 'value' => $value === null ? null : (string) $value];
        }
    }

    /** A stored value, typed. NULL stays NULL. */
    public static function cast(string $type, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'decimal', 'money', 'litres', 'share' => (float) $value,
            'bool' => in_array(strtolower($value), ['1', 'true', 'yes'], true),
            default => $value,
        };
    }

    /** A stored value for a person to read, through Format. An empty one is the house em dash. */
    public static function display(string $type, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => Format::r((float) $value),
            'litres' => Format::litres((float) $value),
            'share' => Format::pct((float) $value * 100, 0),
            'decimal' => Format::n((float) $value, 2),
            'int' => Format::n((int) $value),
            'bool' => in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 'Yes' : 'No',
            default => $value,
        };
    }

    // ---- the Setup screen ------------------------------------------------------

    /**
     * One setting: definition and group value, the sites with their own, history,
     * and the sites that may be given one. A 404 when there is no such key.
     *
     * @return array{setting: object, overrides: Collection<int, object>, history: Collection<int, object>, branches: Collection<int, object>}
     */
    public function get(string $key): array
    {
        $sets = $this->procedures->callSets('agora.usp_Core_GetSetting', [
            'SettingKey' => $key,
            'UserId' => Auth::user()?->Id,
            'GroupBranchId' => $this->groupBranchId(),
        ]);

        $setting = ($sets[0] ?? collect())->first();
        abort_if($setting === null, 404);

        return [
            'setting' => $setting,
            'overrides' => $sets[1] ?? collect(),
            'history' => $sets[2] ?? collect(),
            'branches' => $sets[3] ?? collect(),
        ];
    }

    /**
     * Change a value, give a site its own, or take one away.
     *
     * @param  'edit'|'create'|'remove'  $mode
     */
    public function save(string $key, int $branchId, string $mode, ?string $value, ?string $expectedVersion, string $reason): object
    {
        $permission = ['edit' => 'setup.settings.edit', 'create' => 'setup.settings.save', 'remove' => 'setup.settings.delete'][$mode];

        $result = $this->procedures->write('agora.usp_Core_SaveSetting', [
            'SettingKey' => $key,
            'BranchId' => $branchId,
            'Mode' => $mode,
            'Value' => $value,
            'ExpectedVersion' => $expectedVersion,
            'Reason' => $reason,
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) (Auth::user()?->can($permission) ?? false),
            'GroupBranchId' => $this->groupBranchId(),
        ]);
        $this->forget();

        return $result;
    }

    /** Copy PumpIT's SS_SysDefaults and BRN_Config into agora.Setting, once. */
    public function migrate(?int $userId = null): object
    {
        $status = ($this->procedures->callSets('agora.usp_Migrate_Setting', [
            'UserId' => $userId,
            'GroupBranchId' => $this->groupBranchId(),
        ])[0] ?? collect())->first();
        $this->forget();

        return $status ?? (object) ['Ok' => 0, 'Code' => 'NO_STATUS_ROW', 'Message' => 'usp_Migrate_Setting returned nothing.'];
    }

    /** @return array<string, array<int, array{type: string, value: ?string}>> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $this->rows = [];
        try {
            $found = DB::connection(config('agora.connections.app'))
                ->table(config('agora.schema').'.Setting')
                ->whereNull('DeletedAt')
                ->get(['BranchId', 'SettingKey', 'Value', 'Type']);
        } catch (QueryException) {
            return $this->rows; // not migrated yet: every read is the code default
        }

        foreach ($found as $row) {
            $this->rows[$row->SettingKey][(int) $row->BranchId] = ['type' => (string) $row->Type, 'value' => $row->Value];
        }

        return $this->rows;
    }
}
