<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * A grant: this user may see this branch.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 *
 * The branch being granted IS the row's BranchId, so the branch global scope
 * would filter a user's own grants by the grants it is trying to read.
 * `allowedBranchIds()` reads it with `acrossBranches()` for that reason.
 */
class UserBranch extends BaseModel
{
    protected $table = 'UserBranch';

    public $timestamps = false;
}
