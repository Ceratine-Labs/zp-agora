<?php

namespace Modules\Partners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of an account-customer edit; the rules are
 * agora.usp_Partners_SaveCustomer's (feature-rules §F).
 */
class SaveCustomerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['Reason' => ['required', 'string', 'min:'.(int) config('partners.reason_min', 10), 'max:400']];

        foreach (self::FIELDS as $field => $max) {
            $rules[$field] = [$field === 'CustomerName' ? 'required' : 'nullable', 'string', 'max:'.$max];
            $rules['Expected'.$field] = ['nullable', 'string', 'max:'.$max];
        }

        return $rules;
    }

    /** The columns a customer edit may change, by form field, with BRN_Customers' widths. */
    public const FIELDS = [
        'CustomerName' => 100,
        'ContactPerson' => 35,
        'Department' => 30,
        'JobTitle' => 50,
        'Telephone' => 25,
        'Telephone2' => 25,
    ];
}
