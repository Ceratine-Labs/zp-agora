<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * A permission granted to one person, beside the ones their roles carry.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 * @property int $PermissionId
 *
 * Additive only — see v1__01d_core_user_permission for why there is no deny.
 */
class UserPermission extends BaseModel
{
    protected $table = 'UserPermission';
}
