<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registering or changing an asset — the SHAPE only (feature-rules proposed
 * §F). Whether the type exists, the owner is on the list, the serial is not
 * already registered, the supplier is set up at the site: all of that is
 * agora.usp_Assets_SaveAsset's, and a refusal comes back as words.
 */
class SaveAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required on a registration; ignored on a change (the route names the asset).
            'BranchId' => ['nullable', 'integer'],
            'LocatedBranchId' => ['nullable', 'integer'],
            'AssetGroupCode' => ['required', 'string', 'max:10'],
            'AssetTypeCode' => ['required', 'string', 'max:10'],
            'Make' => ['required', 'string', 'max:100'],
            'Model' => ['nullable', 'string', 'max:100'],
            'SerialNo' => ['nullable', 'string', 'max:100'],
            'Description' => ['nullable', 'string', 'max:200'],
            'AssetOwner' => ['nullable', 'string', 'max:100'],
            'SupplierCode' => ['nullable', 'string', 'max:16'],
            'DatePurchased' => ['required', 'date_format:Y-m-d'],
            'DateAllocated' => ['nullable', 'date_format:Y-m-d'],
            'Cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'Condition' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'AssetTypeCode.required' => 'Choose the type of asset.',
            'Make.required' => 'Give the make — the maker\'s name is how the asset is found again.',
            'DatePurchased.required' => 'Give the date it was bought. Depreciation starts in that month.',
            'Cost.required' => 'Give what it cost, excluding VAT. Zero if it was given to the site.',
        ];
    }

    /**
     * The type picker sends "GROUP|TYPE" in one value, because a type code is
     * only unique within its group (CE28 exists twice). Split it before the
     * rules see it, so the group can never disagree with the type.
     */
    protected function prepareForValidation(): void
    {
        $pair = $this->input('AssetType');

        if (is_string($pair) && str_contains($pair, '|')) {
            [$group, $type] = explode('|', $pair, 2);
            $this->merge(['AssetGroupCode' => $group, 'AssetTypeCode' => $type]);
        }
    }
}
