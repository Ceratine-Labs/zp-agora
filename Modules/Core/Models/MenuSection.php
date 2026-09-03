<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A top-level button in the app bar — Today, Trade, Control, Setup.
 */
class MenuSection extends BaseModel
{
    protected $table = 'MenuSection';

    protected $casts = ['IsActive' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'SectionId', 'Id');
    }
}
