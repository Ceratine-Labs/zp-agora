<?php

namespace Modules\Partners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of a new account customer. The code, the till-account guard and
 * the site are agora.usp_Partners_AddCustomer's (feature-rules §F).
 */
class AddCustomerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'BranchId' => ['required', 'integer'],
            'CustomerName' => ['required', 'string', 'max:100'],
            'AccountNo' => ['required', 'string', 'max:50'],
            'ContactPerson' => ['nullable', 'string', 'max:35'],
            'Department' => ['nullable', 'string', 'max:30'],
            'JobTitle' => ['nullable', 'string', 'max:50'],
            'Telephone' => ['nullable', 'string', 'max:25'],
            'Telephone2' => ['nullable', 'string', 'max:25'],
            'Reason' => ['required', 'string', 'min:'.(int) config('partners.reason_min', 10), 'max:400'],
        ];
    }
}
