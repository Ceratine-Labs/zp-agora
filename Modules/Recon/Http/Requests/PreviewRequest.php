<?php

namespace Modules\Recon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Shape only.
 *
 * The rules live in the procedure — whether a branch has a usable criteria
 * row, whether an extraction position falls inside its narratives, whether
 * two sides may be called matched. This checks that what arrives is the right
 * kind of thing, and nothing else (feature-rules, proposed §F).
 */
class PreviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            ...$this->sharedRules(),
        ];
    }

    /**
     * Everything a preview needs except the site.
     *
     * Shared with GroupPreviewRequest, which is the same form with the site
     * taken out — the master controller runs every site, so asking for one is
     * the question it exists to remove. Duplicating twelve option rules to
     * drop one line would be the copy that drifts.
     *
     * @return array<string, mixed>
     */
    protected function sharedRules(): array
    {
        $options = config('recon.options');

        return [
            'area' => ['required', Rule::in(array_keys(config('recon.areas')))],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],

            // A name on the run. Optional, and capped at the column — 300 in
            // the migration, so a longer one is a refusal here rather than a
            // truncation the person never sees.
            'note' => ['nullable', 'string', 'max:300'],

            'options' => ['array'],
            'options.RuleOrder' => ['nullable', Rule::in(array_keys($options['RuleOrder']['choices']))],
            'options.MopsConvention' => ['nullable', Rule::in(array_keys($options['MopsConvention']['choices']))],
            'options.BatchKey' => ['nullable', Rule::in(array_keys($options['BatchKey']['choices']))],
            'options.MatchMode' => ['nullable', Rule::in(array_keys($options['MatchMode']['choices']))],
            'options.StandaloneRule' => ['nullable', 'boolean'],
            'options.StandaloneLagDays' => ['nullable', 'integer', 'between:0,14'],
            'options.MinBagKeyLen' => ['nullable', 'integer', 'between:4,30'],

            // Bounded because these index into a narrative. An unbounded start
            // is not dangerous — SUBSTRING past the end returns an empty
            // string — but it is always a mistake, and a preview that silently
            // matches nothing is the most expensive kind.
            'options.BankStartOverride' => ['nullable', 'integer', 'between:1,200'],
            'options.BankLenOverride' => ['nullable', 'integer', 'between:1,200'],
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
     * The options this area's procedure actually takes, with the empties
     * dropped so each one falls back to the procedure's own default.
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
