<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A void — shape only: the bag, and the reason.
 *
 * The reason is NOT required here, for the same reason the manager is not on
 * a drop: it is agora.usp_Cash_VoidDropSafe's rule (CASH_VOID_REASON), and the
 * person should see the procedure's refusal, not a form error that says
 * something slightly different. Whether the bag is still in the safe and on no
 * cashup is the procedure's to say as well.
 */
class DropSafeVoidRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'bag_no' => ['required', 'string', 'max:20'],
            'void_reason' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function bagNo(): string
    {
        return trim((string) $this->input('bag_no'));
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('void_reason', ''));

        return $reason === '' ? null : $reason;
    }
}
