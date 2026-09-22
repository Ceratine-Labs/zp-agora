<?php

namespace Modules\Product\Support;

/**
 * The seven booleans a stock line carries, and what to call them.
 *
 * ONE LIST, read by three things that would otherwise each keep their own: the
 * batch form's picker, the FormRequest that validates what came back, and the
 * test that proves the procedure accepts every one of them. The procedure's
 * whitelist is the fourth copy and it cannot be helped — T-SQL cannot import
 * this — so `usp_Product_SetStockItemFlag` names the same seven and
 * `StockItemFlagsTest` sends every one of them through it, which is what
 * catches the two lists parting company.
 *
 * WHAT IS NOT HERE. `IsCritical` looks like an eighth and is not: it lives in
 * `agora.CriticalLine`, keyed by POS code rather than by item number, and a
 * third of that list has no stock line at all. Setting it from this screen
 * would be a write to a different table under a label that says otherwise —
 * Setup → Trading rules → Critical lines is where it belongs.
 *
 * `IsParked` is not here either. It is a property of the OVERRIDE, not of the
 * item — see v1__05_product_tables' header on why the two are named apart —
 * and parking one in bulk is what the batch procedure explicitly refuses.
 */
final class StockItemFlags
{
    /**
     * Column => [label, what switching it ON means].
     *
     * The second half is not decoration. Four of these seven have no
     * server-side rule anywhere in PumpIT — whatever enforced them lived in
     * the old ASP application — so the only explanation a person gets of what
     * they are about to set across forty lines is this one.
     *
     * @var array<string, array{label: string, note: string}>
     */
    public const FLAGS = [
        'IsActive' => [
            'label' => 'Line in use',
            'note' => 'Off retires the line. It stays in the customer\'s estate and in every count that already references it, and the grid shows it as Retired — PumpIT has no active flag at all, which is why a picker built off the legacy table offers dead lines.',
        ],
        'IsMonitoredItem' => [
            'label' => 'Monitored',
            'note' => 'The line is watched for movement and variance.',
        ],
        'IsDoCloseQtyCalc' => [
            'label' => 'Close-quantity calculation',
            'note' => 'The closing quantity is calculated for this line rather than captured. No server-side rule enforces it — see the save procedure.',
        ],
        'IsAllowNegativeQtyIssued' => [
            'label' => 'Allow negative issued',
            'note' => 'An issue may take the line below zero.',
        ],
        'IsAllowNegativeQtyClose' => [
            'label' => 'Allow negative close',
            'note' => 'The line may close below zero.',
        ],
        'IsStockItemPreProduction' => [
            'label' => 'Pre-production input',
            'note' => 'The line is an input consumed by production rather than sold as it is.',
        ],
        'IsPreProductionItem' => [
            'label' => 'Pre-production item',
            'note' => 'The line is produced from other lines.',
        ],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::FLAGS);
    }

    /**
     * The picker's options: column => label.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return array_map(static fn (array $flag): string => $flag['label'], self::FLAGS);
    }

    public static function label(string $flag): string
    {
        return self::FLAGS[$flag]['label'] ?? $flag;
    }
}
