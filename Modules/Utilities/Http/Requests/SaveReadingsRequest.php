<?php

namespace Modules\Utilities\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The readings form.
 *
 * Shape only. agora.usp_Utilities_Save owns every rule — the meter having to
 * be one of the site's, no reading below zero, none in the future, a reason
 * for changing one already captured — and restating any of them here would
 * make two copies that drift. The widths are the customer's columns, so
 * nothing is cut short on its way into PumpIT with nothing saying so.
 *
 * The numbers are NOT validated as numeric here on purpose: a blank box is a
 * meter nobody read and must pass through as blank, and a value that is not a
 * number is refused by the procedure with the meter and the day named, which
 * is a better message than "lines.3.ClosingReading must be a number".
 */
class SaveReadingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'max:1000'],
            'lines.*.UtilityType' => ['required', 'string', 'size:1'],
            'lines.*.MeterNo' => ['required', 'string', 'max:50'],
            'lines.*.TransactionDate' => ['required', 'date_format:Y-m-d'],
            'lines.*.ClosingReading' => ['nullable', 'string', 'max:40'],
            'lines.*.PrepaidUnitsPurchased' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'There are no meters on this form to record.',
        ];
    }
}
