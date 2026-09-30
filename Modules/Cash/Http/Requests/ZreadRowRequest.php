<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only: the seven parts that name one Z-read (see ZreadKey).
 *
 * Whether that Z-read exists, and whether it is this site's, is the
 * procedure's to say — agora.usp_Cash_SetZreadAllocation refuses one it cannot
 * find, by name.
 */
class ZreadRowRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:10'],
            'eod' => ['required', 'string', 'max:8'],
            'term' => ['required', 'string', 'max:3'],
            'log' => ['required', 'string', 'max:10'],
            'user' => ['required', 'string', 'max:6'],
        ];
    }
}
