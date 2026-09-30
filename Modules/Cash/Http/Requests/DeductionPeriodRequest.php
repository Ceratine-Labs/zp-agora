<?php

namespace Modules\Cash\Http\Requests;

use App\Support\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;

/**
 * The sites and the pay period a deduction build or payroll export is for —
 * shape only. Whether the period is closed, not too long, or already exported
 * is the procedures' to say (DEDUCTION_PERIOD, DEDUCTION_EXPORTED).
 *
 * The sites are CHECKED against what the person may see, not merely parsed:
 * they become procedure arguments that never pass through BranchScope. None
 * chosen means every trading site they may see — payroll is estate-wide.
 */
class DeductionPeriodRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', 'min:1'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'note' => ['nullable', 'string', 'max:400'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end of the pay period cannot fall before its start.'];
    }

    /** @return array<int, int> */
    public function branchIds(BranchContext $context): array
    {
        $given = collect((array) $this->input('branches', []))
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $context->maySee($id))
            ->unique()
            ->values()
            ->all();

        if ($given !== []) {
            return $given;
        }

        return Branch::query()->trading()->visibleTo($context->allowed())->ordered()
            ->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->unique()->values()->all();
    }

    public function from(): Carbon
    {
        return Carbon::parse((string) $this->input('from'))->startOfDay();
    }

    public function to(): Carbon
    {
        return Carbon::parse((string) $this->input('to'))->startOfDay();
    }

    public function note(): ?string
    {
        $value = trim((string) $this->input('note', ''));

        return $value === '' ? null : $value;
    }
}
