<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role. Only IsManaged roles participate in customer-configured access.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $Code
 * @property string $Name
 * @property string|null $LandingRoute
 * @property string $Workspace
 * @property bool $IsManaged
 * @property bool $IsReadOnly
 * @property int $SortOrder
 *
 * LandingRoute, Workspace and the users() relation preserve the retired
 * primary-role data for historical inspection. Managed membership is read
 * through RoleAccessService; it never changes a user's landing or workspace.
 */
class Role extends BaseModel
{
    protected $table = 'Role';

    protected $casts = [
        'IsReadOnly' => 'boolean',
        'IsManaged' => 'boolean',
    ];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'RoleId', 'Id');
    }
}
