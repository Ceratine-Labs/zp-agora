<?php

namespace Modules\Partners\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The SHAPE of a Put back; the rules are agora.usp_Partners_ReverseWrite's. */
class ReverseWriteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['Reason' => ['required', 'string', 'min:'.(int) config('partners.reason_min', 10), 'max:400']];
    }
}
