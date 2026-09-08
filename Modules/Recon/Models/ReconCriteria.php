<?php

namespace Modules\Recon\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One Agora-owned override of an extraction rule.
 *
 * A row here REPLACES the customer's `BRN_AutoReconCriteria` row for the same
 * (branch, area, process order) — entirely, not column by column — or ADDS a
 * rule where the branch has none, which is the case for most of the estate
 * (finding 1). `agora.vw_AutoReconCriteria` does the choosing, so the five
 * previews and both drills need to know nothing about any of this.
 *
 * Reads only. Every write goes through agora.usp_Recon_SaveCriteria or
 * agora.usp_Recon_CopyCriteria, which carry the rules — that a reason is
 * always given, that a resolved length is positive, that a copy does not
 * silently overwrite a rule somebody set deliberately.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $BankReconArea
 * @property int $ProcessOrder
 * @property int|null $LegacyAutoReconId
 * @property int|null $BANK_StartPosition
 * @property int|null $BANK_EndPosition
 * @property int|null $BANK_StartPosition2
 * @property int|null $BANK_EndPosition2
 * @property int|null $MOPS_StartPosition
 * @property int|null $MOPS_EndPosition
 * @property string|null $FILTER_Value
 * @property int|null $FILTER_StartPosition
 * @property int|null $FILTER_EndPosition
 * @property bool $IsActive
 * @property string|null $Reason
 * @property int|null $CopiedFromBranchId
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 * @property int|null $CreatedBy
 * @property int|null $UpdatedBy
 */
class ReconCriteria extends BaseModel
{
    protected $table = 'ReconCriteria';

    protected $casts = [
        'BranchId' => 'integer',
        'ProcessOrder' => 'integer',
        'LegacyAutoReconId' => 'integer',
        'BANK_StartPosition' => 'integer',
        'BANK_EndPosition' => 'integer',
        'BANK_StartPosition2' => 'integer',
        'BANK_EndPosition2' => 'integer',
        'MOPS_StartPosition' => 'integer',
        'MOPS_EndPosition' => 'integer',
        'FILTER_StartPosition' => 'integer',
        'FILTER_EndPosition' => 'integer',
        'CopiedFromBranchId' => 'integer',
        'IsActive' => 'boolean',
    ];

    /**
     * Does this override REPLACE a rule, or ADD one the branch never had?
     *
     * The distinction is the whole of finding 1: correcting a wrong rule and
     * giving a site its first rule look identical in a table and are entirely
     * different acts.
     */
    public function isAddition(): bool
    {
        return $this->LegacyAutoReconId === null;
    }

    /**
     * The length the previews will resolve this to.
     *
     * `BANK_EndPosition` is a LENGTH in some areas and an END POSITION in
     * others and the column does not say which (finding 9). This is the same
     * test every preview makes, so the number here is the number they use.
     */
    public function resolvedLength(): ?int
    {
        if ($this->BANK_StartPosition === null || $this->BANK_EndPosition === null) {
            return null;
        }

        return $this->BANK_EndPosition >= $this->BANK_StartPosition
            ? $this->BANK_EndPosition - $this->BANK_StartPosition + 1
            : $this->BANK_EndPosition;
    }
}
