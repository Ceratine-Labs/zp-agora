<?php

namespace Modules\Core\Support;

/**
 * Every setting Agora itself reads, and the default each falls back to (T026).
 *
 * The seeder writes these as the group entity's rows in agora.Setting; the
 * Setup screen changes them there; Settings::for() reads the site's row, then
 * the group's, then the default declared here. So a setting behaves exactly
 * as it did before T026 even on a database the seeder has not reached yet.
 *
 * `config` names the module config literal the threshold used to be read
 * from. It STAYS in the module's config as the shipped default, and is read
 * here and nowhere else: tests/Feature/Core/SettingsTest fails if any other
 * line in Modules/ reads one of these config keys directly (the plan's grep
 * rule). `default` is the fallback when the config key is absent, and is the
 * value for a placeholder that has no config literal yet.
 *
 * `branch` false: one value for the estate, refused per site — an estate-wide
 * report or grid takes one figure for every site, and a site override would
 * make it disagree with the per-site screen.
 *
 * `usedBy` null: nothing reads it yet. It is seeded so the figure the plan
 * names is on the screen and the later task finds it waiting.
 *
 * The named-write switches (stamp_mode, write_mode, issue_mode,
 * fnb_adjust_mode, cash_bag_adjust_mode, missing_issue_mode, reset_issues …)
 * are deliberately NOT here and never will be: CLAUDE.md rule 1 keeps them as
 * config literals, so that Agora starting to write the customer's database is
 * a commit with a name on it, not a value somebody types on a screen.
 */
final class SettingCatalogue
{
    /**
     * @return array<string, array{type: string, description: string, default: mixed, config?: string,
     *     min?: float|int, max?: float|int, choices?: list<string>, nullable?: bool, branch?: bool, usedBy: ?string}>
     */
    public static function all(): array
    {
        $zread = 'Cash · Z-read allocation: the proposer, Auto-allocate and staging';

        return [
            // ---- Cash: Z-read allocation (T036) --------------------------------
            'cash.zread.certain_share' => [
                'type' => 'share', 'config' => 'cash.zread.certain_share', 'default' => 0.75, 'min' => 0.5, 'max' => 1,
                'description' => 'Certain: the same till, shift and person on at least this share of the prior Z-reads from the terminal under the operator ID',
                'usedBy' => $zread,
            ],
            'cash.zread.likely_share' => [
                'type' => 'share', 'config' => 'cash.zread.likely_share', 'default' => 0.60, 'min' => 0.5, 'max' => 1,
                'description' => 'Likely: the operator ID has been the same person on at least this share',
                'usedBy' => $zread,
            ],
            'cash.zread.shift_share' => [
                'type' => 'share', 'config' => 'cash.zread.shift_share', 'default' => 0.60, 'min' => 0.5, 'max' => 1,
                'description' => 'The shift is proposed only when the operator ID runs one shift at least this share of the time',
                'usedBy' => $zread,
            ],
            'cash.zread.history_days' => [
                'type' => 'int', 'config' => 'cash.zread.history_days', 'default' => 90, 'min' => 7, 'max' => 730,
                'description' => 'How many days back the Z-read proposer looks for an operator\'s history',
                'usedBy' => $zread,
            ],
            'cash.zread.auto_accept_confidence' => [
                'type' => 'choice', 'config' => 'cash.zread.auto_accept', 'default' => 'certain', 'choices' => ['certain', 'likely', 'review'],
                'description' => 'Z-read auto-accept confidence: what Auto-allocate stages without a person',
                'usedBy' => $zread,
            ],

            // ---- Cash: daily banking, till balance, drop safe, deductions --------
            'cash.cashup.variance_tolerance' => [
                'type' => 'money', 'config' => 'cash.cashup.variance_tolerance', 'default' => 150.00, 'min' => 0, 'max' => 100000,
                'description' => 'Beyond this variance, either way, confirming a cashup needs a reason, and its chip turns critical',
                'usedBy' => 'Cash · Daily banking: confirm, the variance chips and counts',
            ],
            'cash.tillbalance.variance_floor' => [
                'type' => 'money', 'config' => 'cash.tillbalance.variance_floor', 'default' => 1.00, 'min' => 0, 'max' => 10000,
                'description' => 'The smallest variance, either way, for which a cashup is offered to balance',
                'usedBy' => 'Cash · Till balance: the cashups offered',
            ],
            'cash.dropsafe.reason_threshold' => [
                'type' => 'money', 'config' => 'cash.dropsafe.reason_threshold', 'default' => 2000.00, 'min' => 0, 'max' => 1000000,
                'branch' => false,
                'description' => 'Drop-safe reason threshold: a bag above this needs a reason (PumpIT\'s ReasonIfAmountMoreThanR2000)',
                'usedBy' => 'Cash · Drop safe: capture, correction and the bags grid',
            ],
            'cash.deductions.cash_leniency' => [
                'type' => 'money', 'config' => 'cash.deductions.cash_leniency', 'default' => 3.00, 'min' => 0, 'max' => 1000,
                'branch' => false,
                'description' => 'Cash-short leniency: a cashier short at or inside this is written off; worse is deducted in full',
                'usedBy' => 'Cash · Deductions: the build and the cash-short tab',
            ],

            // ---- Utilities (T056) ------------------------------------------------
            ...self::tariffs(),
            ...self::zeroUsageRuns(),

            // ---- Recon: the value suggestions (T046) ------------------------------
            'recon.suggest.max_lag_days' => [
                'type' => 'int', 'default' => 4, 'min' => 1, 'max' => 14,
                'description' => 'Recon amount window: deposits from up to this many days before the bank line are considered',
                'usedBy' => 'Recon · Suggestions (agora.usp_Recon_SuggestMatches @MaxLagDays)',
            ],
            'recon.suggest.close_max' => [
                'type' => 'money', 'default' => 500.00, 'min' => 0, 'max' => 100000,
                'description' => 'A close suggestion differs by no more than this, whatever the percentage',
                'usedBy' => 'Recon · Suggestions (agora.usp_Recon_SuggestMatches @CloseMax)',
            ],
            'recon.suggest.close_pct' => [
                'type' => 'decimal', 'default' => 1.00, 'min' => 0, 'max' => 100,
                'description' => 'A close suggestion differs by no more than this percentage of the bank side',
                'usedBy' => 'Recon · Suggestions (agora.usp_Recon_SuggestMatches @ClosePct)',
            ],

            // ---- Placeholders the plan names for later tasks -----------------------
            'fuel.pump_variance_litres' => [
                'type' => 'litres', 'default' => 15.000, 'min' => 0, 'max' => 100000,
                'description' => 'Pump variance: a reading this far from the electronic meter or the POS fills is flagged (T030)',
                'usedBy' => null,
            ],
            'purchasing.approval_bands' => [
                'type' => 'string', 'default' => null, 'nullable' => true, 'branch' => false,
                'description' => 'PR approval bands — a placeholder: T054 builds agora.ApprovalBand and fills this',
                'usedBy' => null,
            ],
            'exceptions.escalation_days' => [
                'type' => 'int', 'default' => null, 'nullable' => true, 'min' => 1, 'max' => 365,
                'description' => 'Exception escalation: days an exception may stay open before it escalates (T058); not set yet',
                'usedBy' => null,
            ],
        ];
    }

    /** @return array{type: string, description: string, default: mixed, config?: string, min?: float|int, max?: float|int, choices?: list<string>, nullable?: bool, branch?: bool, usedBy: ?string}|null */
    public static function definition(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** The code default: the module config literal where there is one, else the declared default. */
    public static function default(string $key): mixed
    {
        $definition = self::definition($key);
        if ($definition === null) {
            return null;
        }

        return isset($definition['config']) ? config($definition['config'], $definition['default']) : $definition['default'];
    }

    /**
     * Days without moving or a reading before a meter is flagged: `default` for
     * every type not named, and one setting per type config('utilities.zero_usage_run_days')
     * names (L and M, the generators, empty = never). A type added there gets a
     * length of its own here, as it did there.
     *
     * @return array<string, array{type: string, description: string, default: mixed, config: string, min: int, max: int, nullable: bool, usedBy: string}>
     */
    private static function zeroUsageRuns(): array
    {
        $keys = array_unique(array_merge(['default'], array_map('strval', array_keys((array) config('utilities.zero_usage_run_days', [])))));
        $runs = [];
        foreach ($keys as $type) {
            $runs["utilities.zero_usage_run_days.{$type}"] = [
                'type' => 'int', 'config' => "utilities.zero_usage_run_days.{$type}", 'default' => $type === 'default' ? 3 : null,
                'min' => 1, 'max' => 365, 'nullable' => true,
                'description' => $type === 'default'
                    ? 'Days a meter may go without moving or being read before it is flagged, for any type not named; empty = never'
                    : "Zero-usage run for meter type {$type}; empty = never flagged",
                'usedBy' => 'Utilities · Meter readings: zero-usage runs',
            ];
        }

        return $runs;
    }

    /**
     * Rand per unit per meter type, one setting per type config('utilities.tariffs') names.
     * One value for the estate: the readings grid costs every site in one call.
     *
     * @return array<string, array{type: string, description: string, default: mixed, config: string, min: int, max: int, nullable: bool, branch: bool, usedBy: string}>
     */
    private static function tariffs(): array
    {
        $tariffs = [];
        foreach (array_keys((array) config('utilities.tariffs', [])) as $type) {
            $tariffs["utilities.tariff.{$type}"] = [
                'type' => 'money', 'config' => "utilities.tariffs.{$type}", 'default' => null, 'min' => 0, 'max' => 10000,
                'nullable' => true, 'branch' => false,
                'description' => "Utility tariff, rand per unit, meter type {$type}; empty = no tariff, cost shown as a dash",
                'usedBy' => 'Utilities · Meter readings: cost at tariff',
            ];
        }

        return $tariffs;
    }
}
