<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A collection — shape only: the bags ticked, the security company's bag, the
 * manager handing them over (`handed_by`, so it never collides with the drop
 * form's `manager` on the same page), the guard, the day and the time.
 *
 * Whether each bag is at the site and still in the safe, whether the manager
 * is an employee there and fits PumpIT's column, are
 * agora.usp_Cash_CollectDropSafe's to say — it refuses each by name.
 */
class DropSafeCollectRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'bags' => ['required', 'array', 'min:1', 'max:500'],
            'bags.*' => ['required', 'string', 'max:20'],
            'dbag_no' => ['required', 'string', 'max:20'],
            'handed_by' => ['required', 'string', 'max:11'],
            'security_name' => ['required', 'string', 'max:50'],
            'collection_date' => ['required', 'date_format:Y-m-d'],
            'collection_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'collect_note' => ['nullable', 'string', 'max:400'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bags.required' => 'Tick the bags the security company is taking.',
            'dbag_no.required' => 'Give the number on the security company\'s bag.',
            'handed_by.required' => 'Name the manager handing the bags over.',
            'security_name.required' => 'Give the name of the guard who took the bags.',
            'collection_time.regex' => 'Give the time as hh:mm or hh:mm:ss.',
        ];
    }

    /** @return array<int, string> */
    public function bags(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($bag) => trim((string) $bag), (array) $this->input('bags', [])),
            fn (string $bag) => $bag !== '',
        )));
    }

    /** @return array{dbag: string, manager: string, security: string, date: string, time: ?string} */
    public function collection(): array
    {
        $time = trim((string) $this->input('collection_time', ''));

        return [
            'dbag' => trim((string) $this->input('dbag_no')),
            'manager' => trim((string) $this->input('handed_by')),
            'security' => trim((string) $this->input('security_name')),
            'date' => (string) $this->input('collection_date'),
            'time' => $time === '' ? null : $time,
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->input('collect_note', ''));

        return $note === '' ? null : $note;
    }
}
