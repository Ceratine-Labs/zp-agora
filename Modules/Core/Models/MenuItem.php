<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * One entry in a mega menu, at any depth.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $SectionId
 * @property int|null $ParentId
 * @property string $Label
 * @property string|null $RouteName
 * @property string|null $Url
 * @property string|null $Hint
 * @property string|null $PermissionCode
 * @property string|null $BadgeProvider
 * @property string|null $Icon
 * @property int $SortOrder
 * @property bool $IsMobile
 * @property bool $IsActive
 * @property string $Path
 *
 * Depth is expressed by ParentId alone, so the tree is as deep as the customer
 * wants: a column heading holds links, and a link can itself hold a nested
 * group that expands in place. Nothing here knows how deep it is — the
 * renderer recurses and the CSS indents.
 *
 * `Path` is the stable identity used by MenuService's upsert ("today/capture/
 * pump-readings"). Seeding is therefore idempotent, and renaming a label is an
 * update rather than a duplicate.
 */
class MenuItem extends BaseModel
{
    protected $table = 'MenuItem';

    protected $casts = [
        'IsMobile' => 'boolean',
        'IsActive' => 'boolean',
    ];

    /**
     * Set by MenuService when it builds the tree; not a database column.
     *
     * Typed as the base Collection, not Eloquent's: the tree is assembled from
     * a groupBy whose default for a childless node is a plain collect(), so an
     * Eloquent-only type rejects every leaf.
     */
    /** @var Collection<int, self>|null */
    public ?Collection $childItems = null;

    /** @return BelongsTo<MenuSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'SectionId', 'Id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'ParentId', 'Id');
    }

    /**
     * Where this item points. A named route wins over a raw URL; an item with
     * neither is a heading, and the renderer draws it as text rather than a
     * dead link.
     */
    public function href(): ?string
    {
        if ($this->RouteName && Route::has($this->RouteName)) {
            return route($this->RouteName);
        }

        return $this->Url;
    }

    public function isLink(): bool
    {
        return $this->href() !== null;
    }
}
