<?php

namespace Modules\StockRecon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWeeklyCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['ExpectedVersion' => ['required', 'regex:/^0x[0-9a-fA-F]{16}$/'],
            'Status' => ['required', 'in:Open,Investigating,Waiting,Escalated,Closed'],
            'OwnerId' => ['nullable', 'integer', 'min:1'], 'DueDate' => ['nullable', 'date_format:Y-m-d'],
            'Finding' => ['nullable', 'string', 'max:2000'], 'EvidenceReference' => ['nullable', 'string', 'max:1000'],
            'ClosureOutcome' => ['nullable', 'string', 'max:60'],
            'RecoveryAmount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999999.99', 'decimal:0,2'],
            'RecoveryReference' => ['nullable', 'string', 'max:1000'],
            'CorrectionStatus' => ['nullable', 'in:Y,N'], 'CorrectionReference' => ['nullable', 'string', 'max:1000'],
            'ActionTaken' => ['nullable', 'string', 'max:2000'], 'ActionDate' => ['nullable', 'date_format:Y-m-d'],
            'SignoffNote' => ['nullable', 'string', 'max:1000'],
            'Reason' => ['required', 'string', 'min:10', 'max:400']];
    }
}
