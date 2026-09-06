<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * A permission granted to a role.
 *
 * @property int $Id
 * @property int $RoleId
 * @property int $PermissionId
 */
class RolePermission extends BaseModel
{
    protected $table = 'RolePermission';
}
