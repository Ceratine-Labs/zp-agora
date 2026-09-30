<?php

namespace Modules\Cash\Http\Requests;

/**
 * "Set this Z-read" — the key, plus the three things a person chose.
 *
 * All three are required here because they are required by the procedure
 * (AGORA:CASH_ZREAD_REQUIRED): a Z-read is allocated to a till, a shift and a
 * person or not at all. That they are this site's till, shift and person is
 * the procedure's check, not this one's.
 */
class SetZreadRequest extends ZreadRowRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'till_no' => ['required', 'integer', 'min:1'],
            'shift_no' => ['required', 'integer', 'min:1'],
            'employee_code' => ['required', 'string', 'max:11'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'till_no.required' => 'Choose the till.',
            'shift_no.required' => 'Choose the shift.',
            'employee_code.required' => 'Choose the person.',
        ];
    }
}
