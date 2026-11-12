<?php

namespace Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The SHAPE of a new post; agora.usp_People_AddPost holds the rules and takes the number. */
class AddPostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'BranchId' => ['required', 'integer'],
            'PostDescription' => ['required', 'string', 'max:50'],
            'StockControl' => ['nullable', 'boolean'],
            'Reason' => ['required', 'string', 'min:'.(int) config('people.reason_min', 10), 'max:400'],
        ];
    }
}
