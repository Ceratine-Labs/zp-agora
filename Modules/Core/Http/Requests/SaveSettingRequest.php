<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE of a settings change (T026). The rules — the type, the bounds,
 * whether a site may override, whether it may be empty, the concurrency token
 * — are agora.usp_Core_SaveSetting's (feature-rules §F).
 */
class SaveSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->routeIs('app.setup.settings.overrides.store');
        $removing = $this->routeIs('app.setup.settings.overrides.destroy');

        return [
            'BranchId' => [$creating || ! $removing ? 'required' : 'nullable', 'integer'],
            'Value' => ['nullable', 'string', 'max:400'],
            'ExpectedVersion' => [$creating ? 'nullable' : 'required', 'string', 'max:18'],
            'Reason' => ['required', 'string', 'min:10', 'max:400'],
        ];
    }
}
