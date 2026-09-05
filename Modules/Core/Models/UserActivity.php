<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a person did — signed in, signed out, reset a password.
 *
 * Read here, written by `agora.usp_Core_LogActivity` and nowhere else. That is
 * not ceremony: the proc writes the row AND stamps `User.LastSignInAt` in one
 * transaction, so the log and the column cannot disagree. An Eloquent create
 * beside a separate save is exactly how they would.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 * @property string $Activity
 * @property string|null $Detail
 * @property string|null $IpAddress
 * @property string|null $UserAgent
 * @property Carbon $OccurredAt
 * @property-read User|null $user
 */
class UserActivity extends BaseModel
{
    public const SIGN_IN = 'signin';

    public const SIGN_OUT = 'signout';

    public const SIGN_IN_REFUSED = 'signin-refused';

    public const PASSWORD_RESET = 'password-reset';

    protected $table = 'UserActivity';

    /** Append only. Nothing edits a log row, so there is no UpdatedAt to keep. */
    public const UPDATED_AT = null;

    protected $casts = [
        'OccurredAt' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'UserId', 'Id');
    }
}
