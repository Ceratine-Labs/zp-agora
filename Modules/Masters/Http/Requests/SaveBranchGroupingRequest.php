<?php

namespace Modules\Masters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The shape of a region, brand or format save; usp_Masters_SaveBranchGrouping owns the rules. */
class SaveBranchGroupingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Name' => ['required', 'string', 'max:80'],
            'IsActive' => ['sometimes', 'boolean'],
            'ExpectedVersion' => ['nullable', 'string', 'max:18'],
            'Reason' => ['required', 'string', 'min:'.config('masters.reason_min', 10), 'max:400'],
        ];
    }
}
