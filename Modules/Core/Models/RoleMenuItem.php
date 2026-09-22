<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One menu entry a role may see.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $RoleId
 * @property int $MenuItemId
 *
 * An empty set for everybody means the whole menu — see v1__01f_core_menu_access.
 */
class RoleMenuItem extends BaseModel
{
    protected $table = 'RoleMenuItem';
}
