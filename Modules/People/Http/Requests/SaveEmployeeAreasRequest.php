<?php

namespace Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The SHAPE of an areas save; agora.usp_People_SaveEmployeeAreas holds the rules. */
class SaveEmployeeAreasRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Areas' => ['array', 'max:200'],
            'Areas.*' => ['integer'],
            'ExpectedAreas' => ['required', 'json'],
            'AreaReason' => ['required', 'string', 'min:'.(int) config('people.reason_min', 10), 'max:400'],
        ];
    }
}
