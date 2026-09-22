<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Product\Support\StockItemFlags;

/**
 * The batch flag action on the stock recon master listing.
 *
 * THIN, for the same reason SaveStockItemRequest is: every business rule lives
 * in `agora.usp_Product_SetStockItemFlag` — the line existing at the site it
 * was sent for, the parked override that stops the batch, the reason. This
 * validates SHAPE, plus the two things a form is better at asking than a
 * refusal is at explaining: that a flag was chosen and that a reason was
 * typed.
 *
 * `items` ARRIVES AS `branch:item` STRINGS and leaves here as pairs. The grid
 * composes the key — see StockItemGrid::rowKey — because an item number alone
 * names twenty-two different products across the estate, and the checkbox in
 * the row can only carry one value. Splitting it here rather than in the
 * procedure keeps the JSON the procedure reads in one shape whatever sent it.
 *
 * THERE IS NO CEILING ON THE SELECTION and that is deliberate: a page of the
 * grid is at most the page size, and the tick boxes cannot reach past it. A
 * `max` here would be a number nobody could hit, doing nothing but going
 * stale the day the page size changes.
 */
class SetStockItemFlagsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'flag' => ['required', Rule::in(StockItemFlags::keys())],

            // The two states Ryan asked for, as a word rather than a checkbox:
            // an unticked box does not arrive at all, so a form that sent one
            // could not tell "switch it off" from "the browser dropped it".
            'value' => ['required', 'in:0,1'],

            // 300 is agora.StockItem.Reason's width.
            'reason' => ['required', 'string', 'max:300'],

            'items' => ['required', 'array', 'min:1'],
            // {branch}:{item} — the branch is an integer, the item is
            // NVARCHAR(5) and is NOT constrained to digits: every live value
            // happens to be a number, and nothing in PumpIT's schema says the
            // next one must be.
            'items.*' => ['string', 'regex:/^\d+:.{1,5}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'flag.required' => 'Choose which flag you are setting.',
            'flag.in' => 'That is not a stock line flag.',
            'value.required' => 'Choose whether you are switching it on or off.',
            'reason.required' => 'Say why. It is kept against every line the batch touches.',
            'items.required' => 'Tick the lines you want changed first.',
            'items.*.regex' => 'One of the selected rows is not a site and item number. Reload the grid and tick them again.',
        ];
    }

    /**
     * The selection as the procedure wants it: one object per line, carrying
     * both halves of the key.
     *
     * @return array<int, array{BranchId: int, StockItemNo: string}>
     */
    public function pairs(): array
    {
        return array_map(static function (string $key): array {
            // Limit 2: an item number cannot contain a colon in any live row,
            // but explode() without one would silently drop the tail of a row
            // that did rather than sending it on to be refused.
            [$branch, $item] = explode(':', $key, 2);

            return ['BranchId' => (int) $branch, 'StockItemNo' => $item];
        }, array_values($this->validated('items')));
    }
}
