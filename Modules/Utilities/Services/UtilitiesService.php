<?php

namespace Modules\Utilities\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Utility meter readings (T056).
 *
 * Every figure comes out of a procedure: the capture grid and the month per
 * meter from agora.usp_Utilities_PrepareReadings, the stuck meters from
 * agora.usp_Utilities_ZeroUsage, and the one write — to the customer's
 * PumpIT.dbo.BRN_UtilityTransaction — through agora.usp_Utilities_Save.
 *
 * What is here is machinery: reading the three config values the procedures
 * are handed, shaping the form into the JSON the save procedure reads, and
 * refusing to call the writer at all while writes are switched off.
 */
class UtilitiesService
{
    public function __construct(protected ProcedureService $procedures = new ProcedureService) {}

    /**
     * The capture grid (one row per meter per day), the same range per meter,
     * and the last time readings for the range were recorded through Agora.
     *
     * @return array{lines: Collection<int, object>, meters: Collection<int, object>, lastSave: object|null}
     */
    public function prepare(int $branchId, Carbon $from, Carbon $to, ?string $utilityType = null): array
    {
        $sets = $this->procedures->callSets('agora.usp_Utilities_PrepareReadings', [
            'BranchId' => $branchId,
            'DateFrom' => $from->toDateString(),
            'DateTo' => $to->toDateString(),
            'UtilityType' => $utilityType,
            'TariffsJson' => $this->tariffsJson(),
            'ZeroRunDaysJson' => $this->zeroRunDaysJson(),
        ]);

        return [
            'lines' => $sets[0] ?? collect(),
            'meters' => $sets[1] ?? collect(),
            'lastSave' => ($sets[2] ?? collect())->first(),
        ];
    }

    /**
     * Meters that have stopped moving, for one site, reaching into a window.
     *
     * @return Collection<int, object>
     */
    public function zeroUsage(int $branchId, Carbon $from, Carbon $to): Collection
    {
        return $this->procedures->call('agora.usp_Utilities_ZeroUsage', [
            'BranchId' => $branchId,
            'DateFrom' => $from->toDateString(),
            'DateTo' => $to->toDateString(),
            'ZeroRunDaysJson' => $this->zeroRunDaysJson(),
        ]);
    }

    /**
     * Record readings. The ONLY write this module makes, and it lands in the
     * customer's database — see the procedure's header for exactly what.
     *
     * Blank boxes are not readings and are not sent: a closing left empty
     * means nobody read the meter. Prepaid units ride with the closing reading
     * they were bought against, so a prepaid figure with no closing IS sent,
     * and the procedure refuses it by name rather than this dropping it
     * silently. So is a typed 0 with no prepaid units — 0 / 0 is how PumpIT
     * holds a day nobody read, not a reading (Ryan, 01a0e312-07a0), and the
     * procedure says so rather than this guessing what was meant.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function save(int $branchId, array $lines, bool $allowAmend, ?string $reason, ?int $userId): object
    {
        if (! $this->writesOn()) {
            throw new \LogicException('Utility readings are switched off (utilities.write_mode). The screen should not have offered a Save.');
        }

        return $this->procedures->write('agora.usp_Utilities_Save', [
            'BranchId' => $branchId,
            'LinesJson' => json_encode($this->linesForProcedure($lines), JSON_THROW_ON_ERROR),
            'WriteMode' => (string) config('utilities.write_mode'),
            'AllowAmend' => (int) $allowAmend,
            'Reason' => $reason,
            'UserId' => $userId,
        ]);
    }

    /**
     * The form's rows as the procedure reads them. Numbers travel as the
     * strings the browser submitted (a number input always sends a plain
     * `1234.5`), trimmed and otherwise untouched — the procedure converts, so
     * what it refuses is what was typed rather than what PHP made of it.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, string|null>>
     */
    public function linesForProcedure(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $closing = trim((string) ($line['ClosingReading'] ?? ''));
            $prepaid = trim((string) ($line['PrepaidUnitsPurchased'] ?? ''));

            if ($closing === '' && $prepaid === '') {
                continue;
            }

            $out[] = [
                'UtilityType' => (string) ($line['UtilityType'] ?? ''),
                'MeterNo' => (string) ($line['MeterNo'] ?? ''),
                'TransactionDate' => (string) ($line['TransactionDate'] ?? ''),
                'ClosingReading' => $closing === '' ? null : $closing,
                'PrepaidUnitsPurchased' => $prepaid === '' ? null : $prepaid,
            ];
        }

        return $out;
    }

    public function writesOn(): bool
    {
        return config('utilities.write_mode') === 'live';
    }

    /**
     * The tariffs, as the procedures take them: {"B": 2.95, "L": null, ...}.
     * An empty config is `{}`, never `[]` — the procedure reads an object.
     */
    public function tariffsJson(): string
    {
        $tariffs = [];

        foreach ((array) config('utilities.tariffs', []) as $type => $rate) {
            $tariffs[(string) $type] = is_numeric($rate) ? (float) $rate : null;
        }

        return $tariffs === [] ? '{}' : json_encode($tariffs, JSON_THROW_ON_ERROR);
    }

    /**
     * The meter types with no tariff, for the screen to name. A cost column
     * of dashes needs to say why.
     *
     * @return array<int, string>
     */
    public function typesWithoutTariff(): array
    {
        return array_keys(array_filter(
            (array) config('utilities.tariffs', []),
            fn ($rate) => ! is_numeric($rate),
        ));
    }

    /**
     * The zero-usage run length per meter type, as the procedures take it:
     * {"default": 3, "L": null, "M": null}. A null type is never flagged.
     */
    public function zeroRunDaysJson(): string
    {
        return json_encode($this->zeroRunDays(), JSON_THROW_ON_ERROR);
    }

    /**
     * The same map, cleaned: `default` always present, every other key a
     * one-letter type code, every value a whole number of days or null.
     *
     * @return array<string, int|null>
     */
    public function zeroRunDays(): array
    {
        $configured = (array) config('utilities.zero_usage_run_days', []);
        $days = fn ($v) => is_numeric($v) && (int) $v >= 1 ? (int) $v : null;

        $map = ['default' => array_key_exists('default', $configured) ? $days($configured['default']) : 3];

        foreach ($configured as $type => $value) {
            if (is_string($type) && strlen($type) === 1) {
                $map[$type] = $days($value);
            }
        }

        return $map;
    }

    /**
     * The rule in words, for the screen: what gets flagged, and what never
     * does. Built from the same map the procedures are handed, so the sentence
     * and the flags cannot disagree.
     */
    public function zeroRunRule(): string
    {
        $map = $this->zeroRunDays();
        $default = $map['default'];
        unset($map['default']);

        $never = array_keys(array_filter($map, fn ($v) => $v === null));
        $own = array_filter($map, fn ($v) => $v !== null);

        $rule = $default === null
            ? 'Only the types named below are flagged'
            : "Flagged after {$default} days without moving, or without a reading";

        foreach ($own as $type => $n) {
            $rule .= "; type {$type} after {$n} days";
        }

        if ($never !== []) {
            $rule .= '; never for meter type'.(count($never) === 1 ? ' ' : 's ').implode(' and ', $never);
        }

        return $rule.'.';
    }
}
