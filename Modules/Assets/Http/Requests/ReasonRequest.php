<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one field a correction needs: why. Deleting an asset registered in
 * error, reversing a movement and cancelling a job card all keep the reason
 * against the row, and all three procedures refuse without one.
 */
class ReasonRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:300']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why. It is kept on the record.'];
    }
}
