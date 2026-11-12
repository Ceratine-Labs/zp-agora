<?php

namespace Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of an employee edit. The rules — the post exists at the site, the
 * row has not moved since the form was drawn, the reason is long enough — are
 * agora.usp_People_SaveEmployee's (feature-rules §F).
 */
class SaveEmployeeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'IsActive' => ['required', 'boolean'],
            'PostNo' => ['required', 'integer', 'between:-32768,32767'],
            'NickName' => ['nullable', 'string', 'max:35'],
            'ExpectedIsActive' => ['required', 'boolean'],
            'ExpectedPostNo' => ['required', 'integer'],
            'ExpectedNickName' => ['nullable', 'string', 'max:35'],
            'Reason' => ['required', 'string', 'min:'.(int) config('people.reason_min', 10), 'max:400'],
        ];
    }
}
