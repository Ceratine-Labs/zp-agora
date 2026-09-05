<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * A live forgot-password request.
 *
 * Keyed by address rather than by user, because the request arrives before
 * anybody is identified and an address that matches no user has to behave
 * exactly like one that does — otherwise the form becomes a way of asking
 * which of your staff have accounts.
 *
 * `TokenHash` is a hash. The token itself exists only in the link in the
 * email; nothing on this side can reconstruct it, so a reader of the database
 * cannot mint themselves a session.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $EmailAddress
 * @property string $TokenHash
 * @property Carbon $ExpiresAt
 * @property Carbon|null $UsedAt
 * @property string|null $RequestedIp
 */
class PasswordReset extends BaseModel
{
    protected $table = 'PasswordReset';

    protected $hidden = ['TokenHash'];

    protected $casts = [
        'ExpiresAt' => 'datetime',
        'UsedAt' => 'datetime',
    ];

    public function isLive(): bool
    {
        return $this->UsedAt === null && $this->ExpiresAt->isFuture();
    }
}
