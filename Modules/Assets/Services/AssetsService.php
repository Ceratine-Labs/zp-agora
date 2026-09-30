<?php

namespace Modules\Assets\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/**
 * The Assets module's calls, and nothing else (T057).
 *
 * Every rule lives in an agora.usp_Assets_* procedure, and the depreciation
 * arithmetic in agora.fn_Assets_Depreciation. What this class owns is the
 * seam: turning the config's provisional SARS periods into the JSON the
 * procedures take, and passing who the caller is and which sites they may see
 * — never from the query string.
 *
 * NOTHING HERE WRITES THE CUSTOMER'S DATABASES. The register, its movements
 * and its job cards are Agora's own tables (Ryan, 27 Sep 2026, PM decision
 * 01a0e309); the customer's asset masters and purchase requests are read.
 */
class AssetsService
{
    public function __construct(
        protected ProcedureService $procedures,
        protected BranchContext $context,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  The provisional rates */
    /* ------------------------------------------------------------------ */

    /**
     * The depreciation periods as the procedures take them:
     * {"BE": {"life_months": 72, "residual_pct": 0, "sars_class": "…", "note": "…"}, …}.
     *
     * A group whose `life_years` is null or not a positive number is sent
     * with a null life, which the function reads as "not depreciated" — the
     * same answer as a group missing from the config, stated rather than
     * implied.
     */
    public function ratesJson(): string
    {
        $residual = (float) config('assets.depreciation.residual_pct', 0);
        $rates = [];

        foreach ((array) config('assets.depreciation.groups', []) as $group => $rate) {
            $years = is_array($rate) ? ($rate['life_years'] ?? null) : null;
            $months = is_numeric($years) && (float) $years > 0 ? (int) round((float) $years * 12) : null;

            $rates[(string) $group] = [
                'life_months' => $months,
                'residual_pct' => $residual,
                'sars_class' => is_array($rate) ? ($rate['sars_class'] ?? null) : null,
                'note' => is_array($rate) ? ($rate['note'] ?? null) : null,
            ];
        }

        return $rates === [] ? '{}' : json_encode($rates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** Whether the register must say its rates are provisional. */
    public function ratesProvisional(): bool
    {
        return (bool) config('assets.depreciation.provisional', true);
    }

    public function ratesBasis(): string
    {
        return (string) config('assets.depreciation.basis', '');
    }

    /**
     * The sites this person may see at all, as the procedures take them: a CSV,
     * or null where the grant is the whole estate. Not the same question as
     * the sites they selected.
     */
    public function allowedBranchIds(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }

    /** The branch scope for a list: the pinned site in the branch workspace. */
    public function scopeBranchIds(): ?string
    {
        if ($this->context->isBranchWorkspace() && $this->context->id() !== null) {
            return (string) $this->context->id();
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Reads */
    /* ------------------------------------------------------------------ */

    /**
     * The customer's masters, and the condition vocabulary, for the forms and
     * the masters screen.
     *
     * @return array{groups: Collection<int, object>, types: Collection<int, object>, owners: Collection<int, object>, movementTypes: Collection<int, object>, conditions: Collection<int, object>}
     */
    public function masters(): array
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_Masters', [
            'RatesJson' => $this->ratesJson(),
        ]);

        return [
            'groups' => $sets[0] ?? collect(),
            'types' => $sets[1] ?? collect(),
            'owners' => $sets[2] ?? collect(),
            'movementTypes' => $sets[3] ?? collect(),
            'conditions' => $sets[4] ?? collect(),
        ];
    }

    /**
     * One asset, with its movements and job cards — or null when it is not on
     * that register or this person may see neither of its sites.
     *
     * @return array{asset: object, movements: Collection<int, object>, repairs: Collection<int, object>}|null
     */
    public function asset(int $branchId, int $assetId, ?string $asAt = null): ?array
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_GetAsset', [
            'BranchId' => $branchId,
            'AssetId' => $assetId,
            'RatesJson' => $this->ratesJson(),
            'AsAt' => $asAt,
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);

        $asset = ($sets[0] ?? collect())->first();

        if ($asset === null) {
            return null;
        }

        return [
            'asset' => $asset,
            'movements' => $sets[1] ?? collect(),
            'repairs' => $sets[2] ?? collect(),
        ];
    }

    /**
     * The asset's depreciation schedule, month by month to the end of its life.
     *
     * @return Collection<int, object>
     */
    public function schedule(int $branchId, int $assetId): Collection
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_Depreciate', [
            'AssetBranchId' => $branchId,
            'AssetId' => $assetId,
            'RatesJson' => $this->ratesJson(),
        ]);

        return $sets[2] ?? collect();
    }

    /**
     * Depreciation by group for the sites in scope, as at a date.
     *
     * @return Collection<int, object>
     */
    public function depreciationByGroup(?string $branchIds, ?string $asAt): Collection
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_Depreciate', [
            'BranchIds' => $branchIds,
            'AsAt' => $asAt,
            'RatesJson' => $this->ratesJson(),
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);

        return $sets[1] ?? collect();
    }

    /**
     * One job card, and the purchase request lines behind its cost.
     *
     * @return array{repair: object, lines: Collection<int, object>}|null
     */
    public function repair(int $branchId, int $repairId): ?array
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_GetRepair', [
            'BranchId' => $branchId,
            'RepairId' => $repairId,
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);

        $repair = ($sets[0] ?? collect())->first();

        return $repair === null ? null : ['repair' => $repair, 'lines' => $sets[1] ?? collect()];
    }

    /**
     * Comebacks and the by-supplier tally.
     *
     * @return array{comebacks: Collection<int, object>, suppliers: Collection<int, object>}
     */
    public function comebacks(?string $branchIds, ?string $from, ?string $to, ?string $supplierCode = null): array
    {
        $sets = $this->procedures->callSets('agora.usp_Assets_Comebacks', [
            'BranchIds' => $branchIds,
            'DateFrom' => $from,
            'DateTo' => $to,
            'SupplierCode' => $supplierCode,
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);

        return ['comebacks' => $sets[0] ?? collect(), 'suppliers' => $sets[1] ?? collect()];
    }

    /**
     * The assets a movement or repair form may choose from.
     *
     * @return Collection<int, object>
     */
    public function pickAssets(): Collection
    {
        return $this->procedures->call('agora.usp_Assets_PickAssets', [
            'BranchIds' => $this->scopeBranchIds(),
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Writes — agora.Asset, agora.AssetMovement, agora.AssetRepair only */
    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $fields */
    public function saveAsset(int $branchId, ?int $assetId, array $fields, bool $allowEdit, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_SaveAsset', [
            'BranchId' => $branchId,
            'AssetId' => $assetId,
            'AssetGroupCode' => $fields['AssetGroupCode'] ?? null,
            'AssetTypeCode' => $fields['AssetTypeCode'] ?? null,
            'Make' => $fields['Make'] ?? null,
            'Model' => $fields['Model'] ?? null,
            'SerialNo' => $fields['SerialNo'] ?? null,
            'Description' => $fields['Description'] ?? null,
            'LocatedBranchId' => isset($fields['LocatedBranchId']) && $fields['LocatedBranchId'] !== '' ? (int) $fields['LocatedBranchId'] : null,
            'AssetOwner' => $fields['AssetOwner'] ?? null,
            'SupplierCode' => $fields['SupplierCode'] ?? null,
            'DatePurchased' => $fields['DatePurchased'] ?? null,
            'DateAllocated' => $fields['DateAllocated'] ?? null,
            'Cost' => $fields['Cost'] ?? null,
            'Condition' => $fields['Condition'] ?? 'GOOD',
            'Reason' => $fields['reason'] ?? null,
            'AllowEdit' => (int) $allowEdit,
            'UserId' => $userId,
        ]);
    }

    public function deleteAsset(int $branchId, int $assetId, string $reason, bool $allowDelete, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_DeleteAsset', [
            'BranchId' => $branchId,
            'AssetId' => $assetId,
            'Reason' => $reason,
            'AllowDelete' => (int) $allowDelete,
            'UserId' => $userId,
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    public function move(int $branchId, int $assetId, array $fields, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_Move', [
            'BranchId' => $branchId,
            'AssetId' => $assetId,
            'MovementType' => $fields['MovementType'] ?? null,
            'MovementDate' => $fields['MovementDate'] ?? null,
            'ToBranchId' => isset($fields['ToBranchId']) && $fields['ToBranchId'] !== '' ? (int) $fields['ToBranchId'] : null,
            'Proceeds' => isset($fields['Proceeds']) && $fields['Proceeds'] !== '' ? $fields['Proceeds'] : null,
            'Reason' => $fields['reason'] ?? null,
            'UserId' => $userId,
        ]);
    }

    public function reverseMovement(int $branchId, int $assetId, int $movementId, string $reason, bool $allowReverse, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_ReverseMovement', [
            'BranchId' => $branchId,
            'MovementId' => $movementId,
            'AssetId' => $assetId,
            'Reason' => $reason,
            'AllowReverse' => (int) $allowReverse,
            'UserId' => $userId,
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    public function saveRepair(int $branchId, ?int $repairId, ?int $assetId, array $fields, bool $allowEdit, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_SaveRepair', [
            'BranchId' => $branchId,
            'RepairId' => $repairId,
            'AssetId' => $assetId,
            'CallDate' => $fields['CallDate'] ?? null,
            'SupplierCode' => $fields['SupplierCode'] ?? null,
            'SupplierReference' => $fields['SupplierReference'] ?? null,
            'Problem' => $fields['Problem'] ?? null,
            'TransactionNo' => $fields['TransactionNo'] ?? null,
            'DateExpectedReturn' => $fields['DateExpectedReturn'] ?? null,
            'DateActualReturn' => $fields['DateActualReturn'] ?? null,
            'AllowEdit' => (int) $allowEdit,
            'UserId' => $userId,
        ]);
    }

    public function cancelRepair(int $branchId, int $repairId, string $reason, bool $allowEdit, ?int $userId): object
    {
        return $this->procedures->write('agora.usp_Assets_CancelRepair', [
            'BranchId' => $branchId,
            'RepairId' => $repairId,
            'Reason' => $reason,
            'AllowEdit' => (int) $allowEdit,
            'UserId' => $userId,
        ]);
    }
}
