<?php

namespace Modules\Recon\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Recon\Models\ReconCriteria;

/**
 * The seam between the configuration screen and its procedures.
 *
 * It decides nothing. Whether a reason is required, whether a resolved length
 * is usable, whether a copy may overwrite a rule somebody set deliberately —
 * all of that is in agora.usp_Recon_SaveCriteria and
 * agora.usp_Recon_CopyCriteria, where the customer can read it. If a rule ever
 * exists in both places the procedure is right and this is the bug.
 *
 * WHAT IT WILL NEVER DO is write to BRN_AutoReconCriteria. The whole override
 * design exists so that the customer's table stays read-only to Agora; there
 * is no code path from this class to it, and there must not be one.
 */
class ReconCriteriaService
{
    /** The positions an override carries, in the order the form asks for them. */
    public const POSITIONS = [
        'BankStartPosition' => 'BANK_StartPosition',
        'BankEndPosition' => 'BANK_EndPosition',
        'BankStartPosition2' => 'BANK_StartPosition2',
        'BankEndPosition2' => 'BANK_EndPosition2',
        'MopsStartPosition' => 'MOPS_StartPosition',
        'MopsEndPosition' => 'MOPS_EndPosition',
        'FilterStartPosition' => 'FILTER_StartPosition',
        'FilterEndPosition' => 'FILTER_EndPosition',
    ];

    public function __construct(protected ProcedureService $procedures) {}

    /**
     * Write or replace the override for one rule, and switch it on.
     *
     * @param  array<string, scalar|null>  $values  keyed by the argument names in POSITIONS, plus FilterValue
     */
    public function save(int $branchId, string $area, int $processOrder, array $values, string $reason, ?int $copiedFrom = null): object
    {
        return $this->procedures->write('usp_Recon_SaveCriteria', [
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'ProcessOrder' => $processOrder,
            'Action' => 'save',
            ...$this->arguments($values),
            'Reason' => $reason,
            'CopiedFromBranchId' => $copiedFrom,
            'UserId' => auth()->id(),
        ]);
    }

    /**
     * Switch an override off, or back on.
     *
     * Reverting is parking, not deleting: the row stays with the reason it was
     * made and who made it, and the view stops seeing it — so what is back in
     * force is the customer's own rule, or nothing where they have none.
     */
    public function park(int $branchId, string $area, int $processOrder, string $reason, bool $on = false): object
    {
        return $this->procedures->write('usp_Recon_SaveCriteria', [
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'ProcessOrder' => $processOrder,
            'Action' => $on ? 'unpark' : 'park',
            'Reason' => $reason,
            'UserId' => auth()->id(),
        ]);
    }

    /**
     * Take one site's working configuration across to another.
     *
     * PREVIEWS BY DEFAULT — `$apply` false changes nothing and returns exactly
     * what it would do, rule by rule. Copying configuration onto a site unseen
     * is the kind of bulk change that should never be one press.
     *
     * @return array{plan: Collection<int, object>, status: object}
     */
    public function copy(
        int $fromBranchId,
        int $toBranchId,
        ?string $area = null,
        bool $overwrite = false,
        bool $apply = false,
        ?string $reason = null,
    ): array {
        $sets = $this->procedures->callSets('usp_Recon_CopyCriteria', [
            'FromBranchId' => $fromBranchId,
            'ToBranchId' => $toBranchId,
            'ReconArea' => $area,
            'Overwrite' => (int) $overwrite,
            'Apply' => (int) $apply,
            'Reason' => $reason,
            'UserId' => auth()->id(),
        ]);

        return [
            'plan' => $sets[0] ?? collect(),
            'status' => ($sets[1] ?? collect())->first() ?? (object) ['Ok' => false, 'Code' => 'NO_STATUS', 'Message' => ''],
        ];
    }

    /**
     * One rule as the edit form needs it: the override if there is one, the
     * customer's row if there is not, and both when they differ.
     *
     * @return array{override: ReconCriteria|null, legacy: object|null, effective: object|null}
     */
    public function rule(int $branchId, string $area, int $processOrder): array
    {
        $connection = DB::connection(config('agora.connections.app'));
        $schema = config('agora.schema');

        $where = fn (string $view) => $connection->table("{$schema}.{$view}")
            ->where('BranchId', $branchId)
            ->where('BankReconArea', $area)
            ->where('ProcessOrder', $processOrder)
            ->first();

        return [
            'override' => ReconCriteria::query()
                ->where('BranchId', $branchId)
                ->where('BankReconArea', $area)
                ->where('ProcessOrder', $processOrder)
                ->first(),
            'legacy' => $where('vw_LegacyReconCriteria'),
            'effective' => $where('vw_AutoReconCriteria'),
        ];
    }

    /**
     * The form's field names, mapped onto the procedure's arguments.
     *
     * A missing key is sent as NULL rather than omitted, because an override
     * REPLACES its legacy row: leaving an argument out would mean the
     * procedure's own default, and "absent" has to mean "this rule has no such
     * position" or the replacement has a hole in it.
     *
     * @param  array<string, scalar|null>  $values
     * @return array<string, scalar|null>
     */
    protected function arguments(array $values): array
    {
        $arguments = [];

        foreach (array_keys(self::POSITIONS) as $name) {
            $value = $values[$name] ?? null;
            $arguments[$name] = ($value === null || $value === '') ? null : (int) $value;
        }

        $filter = trim((string) ($values['FilterValue'] ?? ''));
        $arguments['FilterValue'] = $filter === '' ? null : $filter;

        return $arguments;
    }
}
