<?php

namespace Modules\Core\Services;

use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;

/**
 * Builds the navigation tree, and is the only way a module adds to it.
 *
 * The menu is database-driven (plan §3.10) — no blade file lists a link. Each
 * module ships a MenuSeeder that calls `section()` and `item()`, both of which
 * upsert on a stable key, so re-seeding never duplicates and a relabel is an
 * update.
 *
 * `tree()` returns sections each carrying a nested item tree of unbounded
 * depth. The renderer recurses; nothing here or in the schema caps how deep
 * the customer's menu can go.
 */
class MenuService
{
    /** Cache the built tree for the life of the request — the shell asks twice. */
    protected array $trees = [];

    /** Upsert a section (a top-level app bar button). */
    public static function section(string $workspace, string $code, string $label, int $sort = 0): MenuSection
    {
        $branchId = (int) config('agora.group_branch_id');

        $section = MenuSection::query()->acrossBranches()->firstOrNew([
            'BranchId' => $branchId,
            'Workspace' => $workspace,
            'Code' => $code,
        ]);

        $section->fill(['Label' => $label, 'SortOrder' => $sort, 'IsActive' => true]);
        $section->BranchId = $branchId;
        $section->save();

        return $section;
    }

    /**
     * Upsert one item.
     *
     * `path` is the identity: a slash-delimited trail such as
     * "capture/pump-readings" within its section. The parent is addressed by
     * its own path, so a module can hang an item under another module's
     * heading without knowing its primary key.
     *
     * @param  array{label: string, path: string, route?: string, url?: string, parent?: string, hint?: string, permission?: string, badge?: string, icon?: string, sort?: int, mobile?: bool}  $attributes
     */
    public static function item(string $workspace, string $sectionCode, array $attributes): MenuItem
    {
        $branchId = (int) config('agora.group_branch_id');

        $section = MenuSection::query()->acrossBranches()->where([
            'BranchId' => $branchId,
            'Workspace' => $workspace,
            'Code' => $sectionCode,
        ])->firstOrFail();

        $parentId = null;

        if (! empty($attributes['parent'])) {
            $parentId = MenuItem::query()->acrossBranches()->where([
                'BranchId' => $branchId,
                'SectionId' => $section->Id,
                'Path' => $attributes['parent'],
            ])->value('Id');

            if ($parentId === null) {
                throw new \RuntimeException(
                    "Menu item [{$attributes['path']}] names parent [{$attributes['parent']}], which has not been seeded yet. "
                    .'Seed parents before children — the seeder runs in order.'
                );
            }
        }

        $item = MenuItem::query()->acrossBranches()->firstOrNew([
            'BranchId' => $branchId,
            'SectionId' => $section->Id,
            'Path' => $attributes['path'],
        ]);

        $item->fill([
            'ParentId' => $parentId,
            'Label' => $attributes['label'],
            'RouteName' => $attributes['route'] ?? null,
            'Url' => $attributes['url'] ?? null,
            'Hint' => $attributes['hint'] ?? null,
            'PermissionCode' => $attributes['permission'] ?? null,
            'BadgeProvider' => $attributes['badge'] ?? null,
            'Icon' => $attributes['icon'] ?? null,
            'SortOrder' => $attributes['sort'] ?? 0,
            'IsMobile' => $attributes['mobile'] ?? true,
            'IsActive' => true,
        ]);
        $item->BranchId = $branchId;
        $item->SectionId = $section->Id;
        $item->save();

        return $item;
    }

    /**
     * The whole menu for one workspace: sections in order, each with a nested
     * item tree.
     *
     * @return Collection<int, MenuSection>
     */
    public function tree(?string $workspace = null): Collection
    {
        $workspace ??= app(BranchContext::class)->workspace();

        return $this->trees[$workspace] ??= $this->build($workspace);
    }

    protected function build(string $workspace): Collection
    {
        $sections = MenuSection::query()
            ->acrossBranches()
            ->where('Workspace', $workspace)
            ->where('IsActive', true)
            ->orderBy('SortOrder')
            ->get();

        if ($sections->isEmpty()) {
            return $sections;
        }

        $items = MenuItem::query()
            ->acrossBranches()
            ->whereIn('SectionId', $sections->pluck('Id'))
            ->where('IsActive', true)
            ->orderBy('SortOrder')
            ->orderBy('Label')
            ->get();

        // One pass to group by parent, then attach — building the tree with a
        // query per level would issue one round trip per menu column against a
        // database on the other side of the internet.
        $byParent = $items->groupBy(fn (MenuItem $item) => $item->ParentId ?? 0);

        $attach = function (Collection $nodes) use (&$attach, $byParent): Collection {
            return $nodes->each(function (MenuItem $node) use (&$attach, $byParent) {
                $node->childItems = $attach($byParent->get($node->Id, collect()));
            });
        };

        foreach ($sections as $section) {
            $roots = $byParent->get(0, collect())->where('SectionId', $section->Id)->values();
            $section->setRelation('items', $attach($roots));
        }

        return $sections;
    }
}
