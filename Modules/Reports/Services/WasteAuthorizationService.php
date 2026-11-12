<?php

namespace Modules\Reports\Services;

use Illuminate\Validation\ValidationException;

/** Existing Agora decisions remain historical; PumpIT owns current authorization. */
final class WasteAuthorizationService
{
    /** @param array<string, mixed> $data */
    public function decide(array $data, int $userId): void
    {
        throw ValidationException::withMessages(['waste' => 'Waste authorization is managed in PumpIT.']);
    }
}
