<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The critical-line form.
 *
 * Shape only. agora.usp_Product_SaveCriticalLine owns every rule — the code
 * having to exist in the site\'s POS file, the POS system being one of six,
 * the reason being required — and restating any of them here would make two
 * copies that drift. The widths mirror the columns exactly, because a
 * description of 51 characters would otherwise be cut to 50 by SQL Server
 * with nothing saying so.
 */
class SaveCriticalLineRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:save,remove,park,unpark'],
            'BranchId' => ['required', 'integer'],
            // CHAR(10) and CHAR(16) in the legacy table; same here so nothing
            // truncates on the way in.
            'PosSystem' => ['required', 'string', 'max:10'],
            'PosCode' => ['required', 'string', 'max:16'],
            'Description' => ['nullable', 'string', 'max:50'],
            'Category' => ['nullable', 'string', 'max:20'],
            'IsActive' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why. It is kept with the change.',
            'PosCode.required' => 'A critical line is identified by its POS code.',
        ];
    }
}
