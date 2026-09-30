<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only: the four parts that name one cashup, the reason a press may
 * carry, and where to come back to.
 *
 * Whether the cashup exists, whether it is this site's, whether a reason is
 * needed and whether it may be confirmed or closed are the procedures' to
 * say — each refuses by name (feature-rules proposed §F).
 */
class CashupPressRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'till' => ['required', 'integer', 'min:0'],
            'shift' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:400'],
            'from' => ['nullable', 'in:banking,cashups,phone'],
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->input('note', ''));

        return $note === '' ? null : $note;
    }
}
