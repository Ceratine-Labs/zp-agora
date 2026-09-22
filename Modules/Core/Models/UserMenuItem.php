<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One menu entry a single person may see, beside whatever their roles carry.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 * @property int $MenuItemId
 *
 * Additive only — see v1__01f_core_menu_access for why there is no deny.
 */
class UserMenuItem extends BaseModel
{
    protected $table = 'UserMenuItem';
}
