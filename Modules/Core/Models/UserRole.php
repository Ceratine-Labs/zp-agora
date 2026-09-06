<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * A role granted to a person.
 *
 * IsPrimary marks the one grant the landing route comes from. A filtered
 * unique index holds "at most one per user" in the database rather than in a
 * convention — see v1__01b_core_rbac.
 *
 * @property int $Id
 * @property int $UserId
 * @property int $RoleId
 * @property bool $IsPrimary
 */
class UserRole extends BaseModel
{
    protected $table = 'UserRole';

    protected $casts = ['IsPrimary' => 'boolean'];
}
