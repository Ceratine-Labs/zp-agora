<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A top-level button in the app bar — Today, Trade, Control, Setup.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $Workspace
 * @property string $Code
 * @property string $Label
 * @property int $SortOrder
 * @property bool $IsActive
 * @property-read Collection<int, MenuItem> $items
 */
class MenuSection extends BaseModel
{
    protected $table = 'MenuSection';

    protected $casts = ['IsActive' => 'boolean'];

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'SectionId', 'Id');
    }
}
