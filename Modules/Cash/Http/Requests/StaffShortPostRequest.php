<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting one approved short to a cashup — shape only. The cashup is the day,
 * till and shift given, or — none given — the one the short was raised for;
 * whether that cashup can take it is agora.usp_Cash_SaveCashupLeg's to say.
 */
class StaffShortPostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'short' => ['required', 'integer', 'min:1'],
            'post_date' => ['nullable', 'date_format:Y-m-d'],
            'post_till' => ['nullable', 'integer', 'min:1'],
            'post_shift' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function shortNo(): int
    {
        return (int) $this->input('short');
    }

    public function day(): ?string
    {
        return $this->filled('post_date') ? (string) $this->input('post_date') : null;
    }

    public function tillNo(): ?int
    {
        return $this->filled('post_till') ? (int) $this->input('post_till') : null;
    }

    public function shiftNo(): ?int
    {
        return $this->filled('post_shift') ? (int) $this->input('post_shift') : null;
    }
}
