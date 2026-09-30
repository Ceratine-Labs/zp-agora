<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording a movement — the shape. Which movements an asset may make, and
 * in what order, is agora.usp_Assets_Move's.
 */
class MoveAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // "{BranchId}:{AssetId}" from the picker, or the two apart from an asset's page.
            'asset' => ['nullable', 'string', 'regex:/^\d+:\d+$/'],
            'MovementType' => ['required', 'string', 'max:10'],
            'MovementDate' => ['required', 'date_format:Y-m-d'],
            'ToBranchId' => ['nullable', 'integer'],
            'Proceeds' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'reason' => ['nullable', 'string', 'max:300'],
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
