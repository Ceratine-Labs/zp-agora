<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An approver's decision on one short — shape only. Whether the short is still
 * waiting, and whether a decline carries a reason, are the procedures' to say
 * (CASH_SHORT_DECIDED, CASH_SHORT_DECLINE_REASON), so a decline with no reason
 * reaches agora.usp_Cash_DeclineStaffShort and comes back refused by name.
 */
class StaffShortDecisionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'short' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:400'],
            'decline_reason' => ['nullable', 'string', 'max:400'],
            'withdraw_reason' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function shortNo(): int
    {
        return (int) $this->input('short');
    }

    public function note(): ?string
    {
        $value = trim((string) $this->input('note', ''));

        return $value === '' ? null : $value;
    }

    public function reason(): ?string
    {
        $value = trim((string) $this->input('decline_reason', ''));

        return $value === '' ? null : $value;
    }

    /** Required by agora.usp_Cash_WithdrawStaffShort (CASH_SHORT_WITHDRAW_REASON), not here. */
    public function withdrawReason(): ?string
    {
        $value = trim((string) $this->input('withdraw_reason', ''));

        return $value === '' ? null : $value;
    }
}
