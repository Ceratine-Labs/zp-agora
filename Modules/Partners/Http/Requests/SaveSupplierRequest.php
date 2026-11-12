<?php

namespace Modules\Partners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of a supplier edit. The rules — the row has not moved since the
 * form was drawn, the reason is long enough, the name is not blank — are
 * agora.usp_Partners_SaveSupplier's (feature-rules §F).
 */
class SaveSupplierRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'SupplierName' => ['required', 'string', 'max:55'],
            'ContactPerson' => ['nullable', 'string', 'max:50'],
            'Department' => ['nullable', 'string', 'max:30'],
            'IsActive' => ['required', 'boolean'],
            'IsCash' => ['required', 'boolean'],
            'IsStock' => ['required', 'boolean'],
            'ExpectedSupplierName' => ['nullable', 'string', 'max:55'],
            'ExpectedContactPerson' => ['nullable', 'string', 'max:50'],
            'ExpectedDepartment' => ['nullable', 'string', 'max:30'],
            'ExpectedIsActive' => ['required', 'boolean'],
            'ExpectedIsCash' => ['required', 'boolean'],
            'ExpectedIsStock' => ['required', 'boolean'],
            'Reason' => ['required', 'string', 'min:'.(int) config('partners.reason_min', 10), 'max:400'],
        ];
    }
}
