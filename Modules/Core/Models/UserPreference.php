<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One thing a person has chosen for themselves.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 * @property string $PrefKey
 * @property string|null $PrefValue
 */
class UserPreference extends BaseModel
{
    public const THEME = 'theme';

    protected $table = 'UserPreference';

    /**
     * Read one preference for a user.
     *
     * Reads across branches: a preference belongs to the person, not to the
     * site they happen to be looking at, so it must survive a change of scope.
     */
    public static function get(int $userId, string $key, ?string $default = null): ?string
    {
        return static::query()
            ->acrossBranches()
            ->where('UserId', $userId)
            ->where('PrefKey', $key)
            ->value('PrefValue') ?? $default;
    }

    /** Write one preference, replacing whatever was there. */
    public static function put(int $userId, string $key, ?string $value): void
    {
        $branchId = (int) config('agora.group_branch_id');

        $preference = static::query()->acrossBranches()->firstOrNew([
            'BranchId' => $branchId,
            'UserId' => $userId,
            'PrefKey' => $key,
        ]);

        $preference->BranchId = $branchId;
        $preference->UserId = $userId;
        $preference->PrefKey = $key;
        $preference->PrefValue = $value;
        $preference->save();
    }
}
