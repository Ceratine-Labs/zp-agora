<?php

namespace Modules\StockRecon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCountingAreaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'IsPreparedFood' => ['required', 'boolean'],
            'ExpectedVersion' => ['required', 'regex:/^(new|0x[0-9a-fA-F]{16})$/'],
            'ExpectedFood' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:400'],
            'confirm' => ['required', 'accepted'],
        ];
    }
}
