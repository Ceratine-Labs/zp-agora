<?php

namespace Modules\Recon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('recon.runs.view') && $user->can('recon.runs.close');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_token' => ['required', 'string', 'max:65536'],
            'mode' => ['required', 'in:selected,all'],
            'items' => ['required_if:mode,selected', 'prohibited_if:mode,all', 'array', 'min:1', 'max:200'],
            'items.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
