<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only: the screen's scope (to come back to), the two cashups — each
 * posted as "YYYY-MM-DD|till|shift", the value the screen's list gives it —
 * the amount and an optional note.
 *
 * Whether each cashup exists, has one Z-read, is open, whether the two differ
 * and whether the same till balance is already waiting are the procedure's to
 * say — it refuses each by name (feature-rules proposed §F).
 */
class TillBalanceRequest extends FormRequest
{
    private const CASHUP = '/^\d{4}-\d{2}-\d{2}\|\d{1,6}\|\d{1,6}$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'employee' => ['nullable', 'string', 'max:11'],
            'from' => ['required', 'string', 'regex:'.self::CASHUP],
            'to' => ['required', 'string', 'regex:'.self::CASHUP],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:400'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.required' => 'Choose the cashup the amount comes off.',
            'to.required' => 'Choose the cashup the amount goes onto.',
            'amount.regex' => 'Give the amount in rands and cents, like 63.67.',
        ];
    }

    /** @return array{date: string, till: int, shift: int} */
    public function cashup(string $field): array
    {
        [$date, $till, $shift] = explode('|', (string) $this->input($field));

        return ['date' => $date, 'till' => (int) $till, 'shift' => (int) $shift];
    }

    public function amount(): string
    {
        return (string) $this->input('amount');
    }

    public function note(): ?string
    {
        $note = trim((string) $this->input('note', ''));

        return $note === '' ? null : $note;
    }
}
