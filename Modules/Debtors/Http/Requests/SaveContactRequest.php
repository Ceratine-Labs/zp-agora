<?php

namespace Modules\Debtors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where a debtor's statement goes. The shape only: whether the addresses are
 * addresses, and whether the account exists at the site, is
 * agora.usp_Debtors_SaveContact's to say.
 */
class SaveContactRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'max:200'],
            'cc_email' => ['nullable', 'string', 'max:400'],
            'auto_send' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
