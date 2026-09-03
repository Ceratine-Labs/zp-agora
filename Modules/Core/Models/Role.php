<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the five roles from plan §2, each with its own landing page.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $Code
 * @property string $Name
 * @property string|null $LandingRoute
 * @property string $Workspace
 * @property bool $IsReadOnly
 * @property int $SortOrder
 *
 * The landing route is data rather than a match statement in a controller,
 * because "where does Finance land" is a question the business changes its
 * mind about and a developer should not have to be involved.
 */
class Role extends BaseModel
{
    protected $table = 'Role';

    protected $casts = [
        'IsReadOnly' => 'boolean',
    ];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'RoleId', 'Id');
    }
}
