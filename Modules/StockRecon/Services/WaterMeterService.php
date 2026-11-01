<?php

namespace Modules\StockRecon\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/** Scope and vocabulary for the read-only cumulative water-meter report. */
class WaterMeterService
{
    public const STATUSES = [
        'valid' => 'Comparable interval',
        'missing_baseline' => 'Missing baseline',
        'missing_reading' => 'Missing reading',
        'negative_reading' => 'Negative reading',
        'invalid_baseline' => 'Invalid baseline',
        'reset' => 'Zero reset — check source',
        'backward' => 'Backward reading — check source',
        'reading_gap' => 'Missing shift reading',
        'unknown_schedule' => 'Check shift schedule',
        'duplicate' => 'Duplicate shift readings',
        'missing_pos' => 'Missing POS litres',
        'negative_pos' => 'Negative POS litres',
    ];

    public function __construct(private readonly BranchContext $context, private readonly ProcedureService $procedures) {}

    public function allowedBranchIds(): ?string
    {
        if ($this->context->isBranchWorkspace()) {
            $branch = $this->context->id();

            return (string) ($branch !== null && $branch > 0 && $this->context->maySee($branch) ? $branch : -1);
        }

        return $this->context->allowed() === [] ? null : implode(',', $this->context->allowed());
    }

    /** @return Collection<int, object> */
    public function sites(): Collection
    {
        return $this->procedures->call('agora.usp_StockRecon_WaterMeterSites', [
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);
    }
}
