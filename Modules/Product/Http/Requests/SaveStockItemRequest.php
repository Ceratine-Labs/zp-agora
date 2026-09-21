<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The stock master editor's form.
 *
 * DELIBERATELY THIN, and the reason is the house rule: if a rule exists in
 * both a procedure and PHP, the procedure is right and the PHP is the bug.
 * `agora.usp_Product_SaveStockItem` owns every business rule here — the POS
 * code being unique per site AND POS system, the area existing at this site,
 * the price type being one of three. Restating any of those would produce two
 * copies that drift, and the copy people read would be the wrong one.
 *
 * So this validates SHAPE only: that the types are what the procedure's
 * parameters can accept, and that nothing arrives long enough to be truncated
 * silently. The lengths mirror the column widths exactly, because a
 * description of 51 characters would otherwise be cut to 50 by SQL Server
 * without anything saying so.
 *
 * The one thing it does enforce is the reason, and only because a form that
 * lets you press Save and then refuses is worse than one that asks first.
 * The procedure refuses it too.
 */
class SaveStockItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:save,retire,park,unpark'],

            // 300 is agora.StockItem.Reason's width.
            'reason' => ['required', 'string', 'max:300'],

            // NVARCHAR(5), per branch. Not numeric: every live value happens
            // to be a number but nothing in the schema says it must be, and a
            // numeric rule here would refuse an item the database accepts.
            'StockItemNo' => ['required', 'string', 'max:5'],

            'StockItemDescription' => ['nullable', 'string', 'max:50'],
            'AreaNo' => ['nullable', 'integer'],
            'PosSystem' => ['nullable', 'string', 'max:10'],
            'POSCode' => ['nullable', 'string', 'max:50'],

            // DECIMAL(18,4) — four places, because fifty live prices carry
            // more than two and MigrationHelper::money's (18,2) would restate
            // what those lines are counted at.
            'SellingPrice' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'PriceType' => ['nullable', 'string', 'max:20'],
            'Factor' => ['nullable', 'numeric'],
            'UOMCode' => ['nullable', 'string', 'max:10'],

            'IssueMultiple' => ['nullable', 'numeric'],
            'IssueMultiplePercentage' => ['nullable', 'numeric'],
            'QtyVarAllowance' => ['nullable', 'numeric'],

            'IsMonitoredItem' => ['nullable', 'boolean'],
            'IsDoCloseQtyCalc' => ['nullable', 'boolean'],
            'IsAllowNegativeQtyIssued' => ['nullable', 'boolean'],
            'IsAllowNegativeQtyClose' => ['nullable', 'boolean'],
            'IsStockItemPreProduction' => ['nullable', 'boolean'],
            'IsPreProductionItem' => ['nullable', 'boolean'],
            'PreProductionTypeNo' => ['nullable', 'integer'],
            'Ratio' => ['nullable', 'numeric'],
            'ProduceLimitPercentage' => ['nullable', 'numeric'],
            'IsActive' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you are changing this line. It is kept with the change.',
            'StockItemNo.max' => 'An item number is at most five characters — that is the width PumpIT gives it, and 6.2 million count lines reference it.',
        ];
    }
}
