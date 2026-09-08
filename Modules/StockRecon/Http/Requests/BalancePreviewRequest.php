<?php

namespace Modules\StockRecon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Shape only.
 *
 * The rules live in the procedure — whether the area is configured at this
 * site, whether a chain can be balanced at all, what counts as an implausible
 * amendment. This checks that what arrives is the right KIND of thing and
 * nothing else (feature-rules, proposed §F).
 *
 * The caps are validated but not rejected out of range: StockReconService
 * clamps them, because a plausibility cap that answers 422 to somebody who
 * typed 1.5 has stopped them working over a setting whose only effect is to
 * block more or fewer chains.
 */
class BalancePreviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            // Absent, empty or 0 all mean every area at the site. The
            // procedure takes NULL for that; 0 is the legacy sentinel and is
            // deliberately not carried forward into a stored run, where a
            // reader six months later would have to know what it meant.
            'area_no' => ['nullable', 'integer', 'min:0'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],

            // A name on the run. Capped at the column — 400 in the migration —
            // so a longer one is a refusal here rather than a truncation the
            // person never sees.
            'note' => ['nullable', 'string', 'max:400'],

            'options' => ['array'],
            'options.MaxAmendmentPct' => ['nullable', 'numeric'],
            'options.MaxAmendment' => ['nullable', 'integer'],
            'options.MinShiftsInChain' => ['nullable', 'integer'],
            'options.UseOriginalCounts' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the period cannot fall before its start.',
        ];
    }

    public function from(): Carbon
    {
        return Carbon::parse($this->date('from'))->startOfDay();
    }

    public function to(): Carbon
    {
        return Carbon::parse($this->date('to'))->startOfDay();
    }

    /** Null for every area at the site — never 0, which is the legacy sentinel. */
    public function areaNo(): ?int
    {
        $area = $this->integer('area_no');

        return $area > 0 ? $area : null;
    }

    /**
     * What to call this run, or null.
     *
     * Blank and absent are the same thing: a person who cleared the field did
     * not name the run, and storing an empty string would make "has a name"
     * two checks everywhere instead of one.
     */
    public function note(): ?string
    {
        $note = trim((string) $this->input('note', ''));

        return $note === '' ? null : $note;
    }

    /**
     * The caps, with the empties dropped so each falls back to its declared
     * default rather than to a null the procedure would have to interpret.
     *
     * @return array<string, scalar|null>
     */
    public function options(): array
    {
        /** @var array<string, scalar|null> $options */
        $options = $this->validated()['options'] ?? [];

        return array_filter($options, fn (mixed $value) => $value !== null && $value !== '');
    }
}
