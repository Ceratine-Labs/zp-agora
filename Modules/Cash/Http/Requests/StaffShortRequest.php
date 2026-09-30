<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff short, raised — shape only.
 *
 * The person, the amount, the day and the reason are required to be well
 * formed here, because a mistyped amount is a form error and not a business
 * rule. Everything that IS a rule — that the person is on the site's employee
 * list, that the reason is an active code, that "other" carries a note, that
 * the day has happened, that the same short is not already waiting — is
 * agora.usp_Cash_RaiseStaffShort's to say, and it refuses each by name. That is
 * why the note is not required here even for "other": the refusal
 * CASH_SHORT_NOTE is what the person should see.
 */
class StaffShortRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'from' => ['nullable', 'in:desk,phone'],
            'employee' => ['required', 'string', 'max:11'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'day' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:400'],
            'till' => ['nullable', 'integer', 'min:1', 'required_with:shift'],
            'shift' => ['nullable', 'integer', 'min:1', 'required_with:till'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee.required' => 'Name the person the short is against.',
            'amount.regex' => 'Give the amount in rands and cents, like 120.50.',
            'amount.gt' => 'Give the amount short, above nil.',
            'day.required' => 'Give the day of the shift the short came from.',
            'reason.required' => 'Choose the reason.',
            'till.required_with' => 'Name the till as well as the shift, or neither.',
            'shift.required_with' => 'Name the shift as well as the till, or neither.',
        ];
    }

    /**
     * The short as the procedure takes it.
     *
     * @return array{employee: string, amount: string, day: string, reason: string, note: ?string, till: ?int, shift: ?int}
     */
    public function short(): array
    {
        return [
            'employee' => trim((string) $this->input('employee')),
            'amount' => (string) $this->input('amount'),
            'day' => (string) $this->input('day'),
            'reason' => trim((string) $this->input('reason')),
            'note' => $this->text('note'),
            'till' => $this->filled('till') ? (int) $this->input('till') : null,
            'shift' => $this->filled('shift') ? (int) $this->input('shift') : null,
        ];
    }

    public function fromPhone(): bool
    {
        return $this->input('from') === 'phone';
    }

    private function text(string $field): ?string
    {
        $value = trim((string) $this->input($field, ''));

        return $value === '' ? null : $value;
    }
}
