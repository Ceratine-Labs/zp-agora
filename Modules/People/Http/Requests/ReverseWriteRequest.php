<?php

namespace Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The SHAPE of a Put back; agora.usp_People_ReverseWrite decides whether it may. */
class ReverseWriteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Reason' => ['required', 'string', 'min:'.(int) config('people.reason_min', 10), 'max:400'],
        ];
    }
}
