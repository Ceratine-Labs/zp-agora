<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A bag, dropped or corrected — shape only.
 *
 * The number, the day, the time, the amount and the reason are required to be
 * well formed here, because a mistyped amount is a form error and not a
 * business rule. Everything that IS a rule — the manager, the reason over the
 * threshold, the fault reference, whether the people exist at the site,
 * whether the bag number is free, whether the bag may still be changed — is
 * agora.usp_Cash_SaveDropSafe's to say, and it refuses each by name. That is
 * why the manager is NOT required here: a R2,500 bag with no manager must reach
 * the procedure and come back as CASH_DROP_MANAGER, not stop at a form error
 * that means something different on another screen.
 */
class DropSafeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'from' => ['nullable', 'in:desk,phone'],
            'bag_no' => ['required', 'string', 'max:20'],
            'new_bag_no' => ['nullable', 'string', 'max:20'],
            'drop_date' => ['required', 'date_format:Y-m-d'],
            'drop_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reason' => ['required', 'integer'],
            'ref_no' => ['nullable', 'string', 'max:20'],
            'limit_reason' => ['nullable', 'string', 'max:50'],
            'cashier' => ['required', 'string', 'max:11'],
            'manager' => ['nullable', 'string', 'max:11'],
            'note' => ['nullable', 'string', 'max:400'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bag_no.required' => 'Give the number printed on the bag.',
            'drop_date.required' => 'Give the day the bag was dropped.',
            'drop_time.regex' => 'Give the time as hh:mm or hh:mm:ss.',
            'amount.regex' => 'Give the amount in rands and cents, like 2500.00.',
            'reason.required' => 'Say why the bag went into the manual safe.',
            'cashier.required' => 'Name the cashier whose cash is in the bag.',
        ];
    }

    /**
     * The bag as the procedure takes it.
     *
     * @return array{date: string, time: ?string, amount: string, reason: int, ref: ?string, limitReason: ?string, cashier: string, manager: ?string}
     */
    public function bag(): array
    {
        return [
            'date' => (string) $this->input('drop_date'),
            'time' => $this->text('drop_time'),
            'amount' => (string) $this->input('amount'),
            'reason' => (int) $this->input('reason'),
            'ref' => $this->text('ref_no'),
            'limitReason' => $this->text('limit_reason'),
            'cashier' => (string) $this->text('cashier'),
            'manager' => $this->text('manager'),
        ];
    }

    public function bagNo(): string
    {
        return trim((string) $this->input('bag_no'));
    }

    public function newBagNo(): ?string
    {
        return $this->text('new_bag_no');
    }

    public function note(): ?string
    {
        return $this->text('note');
    }

    private function text(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
