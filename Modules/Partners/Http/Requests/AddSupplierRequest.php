<?php

namespace Modules\Partners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of a new supplier. The code, the duplicate guard and the sites
 * are agora.usp_Partners_AddSupplier's (feature-rules §F).
 */
class AddSupplierRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Series' => ['required', 'in:APS,APE'],
            'SupplierName' => ['required', 'string', 'max:55'],
            'ContactPerson' => ['nullable', 'string', 'max:50'],
            'Department' => ['nullable', 'string', 'max:30'],
            'IsStock' => ['required', 'boolean'],
            'IsCash' => ['required', 'boolean'],
            'Reason' => ['required', 'string', 'min:'.(int) config('partners.reason_min', 10), 'max:400'],
        ];
    }
}
