<?php

namespace Modules\Masters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\Branch;

/**
 * The SHAPE of a branch edit. The rules — an administrative entity is
 * non-trading, a grouping must be in use, the reason, the concurrency token —
 * are agora.usp_Masters_SaveBranch's (feature-rules §F).
 */
class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Name' => ['required', 'string', 'max:100'],
            'RegionId' => ['nullable', 'integer'],
            'BrandId' => ['nullable', 'integer'],
            'FormatId' => ['nullable', 'integer'],
            'Status' => ['required', 'string', 'in:'.implode(',', Branch::STATUSES)],
            'POSType' => ['required', 'string', 'in:'.implode(',', Branch::POS_TYPES)],
            'IsAdminEntity' => ['required', 'boolean'],
            'IsActive' => ['required', 'boolean'],
            'ProfitCentreIds' => ['sometimes', 'array', 'max:50'],
            'ProfitCentreIds.*' => ['integer', 'distinct'],
            'ExpectedVersion' => ['required', 'string', 'max:18'],
            'Reason' => ['required', 'string', 'min:'.config('masters.reason_min', 10), 'max:400'],
        ];
    }
}
