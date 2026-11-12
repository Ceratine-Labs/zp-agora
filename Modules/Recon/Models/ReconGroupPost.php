<?php

namespace Modules\Recon\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One site's post within an every-site group: the answer its queued job gave.
 *
 * Written by ReconGroupService::queuePosts() (the press) and postSite() (the
 * job). The truth about what was stamped is always the RUN — usp_Recon_Commit
 * sets ReconRun.Status = 'committed' in the same transaction as the stamps —
 * so this row is how the screen and a retry find out, never a second ledger.
 * See v1__13m_recon_group_post.php for the statuses.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $GroupRef
 * @property int $RunId
 * @property string $Status
 * @property int $Attempts
 * @property string|null $Code
 * @property string|null $Message
 * @property int $CommittedRows
 * @property string $CommittedTotal
 * @property Carbon|null $QueuedAt
 * @property Carbon|null $StartedAt
 * @property Carbon|null $FinishedAt
 * @property int|null $CreatedBy
 */
class ReconGroupPost extends BaseModel
{
    protected $table = 'ReconGroupPost';

    protected $casts = [
        'BranchId' => 'integer',
        'RunId' => 'integer',
        'Attempts' => 'integer',
        'CommittedRows' => 'integer',
        'CreatedBy' => 'integer',
        'QueuedAt' => 'datetime',
        'StartedAt' => 'datetime',
        'FinishedAt' => 'datetime',
    ];

    /** A job is on the queue for it or in a worker, and has not been given up on. */
    public function isActive(): bool
    {
        return in_array($this->Status, ['queued', 'running'], true) && ! $this->isStale();
    }

    /**
     * Queued or running for longer than any job may take: the worker is down
     * or the queue lost it. The screen says so and lets it be pressed again;
     * usp_Recon_Commit still refuses a run that was in fact committed.
     */
    public function isStale(): bool
    {
        if (! in_array($this->Status, ['queued', 'running'], true)) {
            return false;
        }

        $since = $this->StartedAt ?? $this->QueuedAt;

        return $since !== null
            && $since->lt(now()->subMinutes((int) config('recon.group_post.stale_after', 30)));
    }

    /** Answered: committed, refused or failed. */
    public function isFinished(): bool
    {
        return ! in_array($this->Status, ['queued', 'running'], true);
    }
}
