<?php

namespace Modules\Cash\Http\Requests;

/**
 * Shape only: one cashup, one leg, and the pool rows ticked — each the PoolKey
 * the pool list gave it, posted back as it came.
 *
 * Whether the leg may be written, whether each row is this site's and still
 * unbanked, and whether its account resolves, are the procedure's to say
 * (agora.usp_Cash_SaveCashupLeg refuses each by name).
 */
class CashupLegRequest extends CashupPressRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'channel' => ['required', 'string', 'max:20'],
            'keys' => ['required', 'array', 'min:1', 'max:500'],
            'keys.*' => ['required', 'string', 'max:4000'],
        ];
    }

    /** @return array<int, string> */
    public function poolKeys(): array
    {
        return array_values(array_map('strval', (array) $this->input('keys', [])));
    }
}
