<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Raising or updating a job card — the shape. Whether the purchase request
 * exists at the site, was declined, or is for a different supplier is
 * agora.usp_Assets_SaveRepair's.
 */
class SaveRepairRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset' => ['nullable', 'string', 'regex:/^\d+:\d+$/'],
            'CallDate' => ['required', 'date_format:Y-m-d'],
            'Problem' => ['required', 'string', 'max:1000'],
            'SupplierCode' => ['nullable', 'string', 'max:16'],
            'SupplierReference' => ['nullable', 'string', 'max:100'],
            'TransactionNo' => ['nullable', 'string', 'max:30'],
            'DateExpectedReturn' => ['nullable', 'date_format:Y-m-d'],
            'DateActualReturn' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'Problem.required' => 'Say what is wrong with it.',
            'CallDate.required' => 'Give the date the repair was called in.',
        ];
    }

    /** @return array{0:int, 1:int}|null */
    public function assetKey(): ?array
    {
        $key = (string) $this->validated('asset', '');

        if (preg_match('/^(\d+):(\d+)$/', $key, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
