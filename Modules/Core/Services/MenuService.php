<?php

namespace Modules\Core\Services;

use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\User;

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
 *
 * WHAT COMES BACK IS FILTERED TO THE PERSON ASKING (customer request,
 * 22 Sep 2026). MenuAccessService answers which entries they are ticked for
 * and whether they hold the permission an entry names; entries that fail
 * either are not in the tree. `rawTree()` is the unfiltered one, and the ONLY
 * caller that should want it is the screen where the ticks are set.
 */
class MenuService
{
    /** Cache the built tree for the life of the request — the shell asks twice. */
    /** @var array<string, Collection<int, MenuSection>> */
    protected array $trees = [];

    public function __construct(protected MenuAccessService $access) {}

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

        // Before the save, because after it the model no longer knows what it
        // used to point at. EnforceMenuAccess caches route name -> item ids
        // forever, on the grounds that the map only changes when a seeder runs
        // — so a seeder that repoints an item is exactly the moment that cache
        // has to go, and both the old route and the new one are affected.
        $previous = (string) $item->getOriginal('RouteName');
        $item->save();

        foreach (array_filter([$previous, (string) $item->RouteName]) as $route) {
            Cache::forget('agora.menu.route.'.$route);
        }

        return $item;
    }

    /**
     * The whole menu for one workspace: sections in order, each with a nested
     * item tree.
     *
     * @return Collection<int, MenuSection>
     */
    public function tree(?string $workspace = null, ?User $user = null): Collection
    {
        $workspace ??= app(BranchContext::class)->workspace();
        $user ??= auth()->user();

        // Keyed by the person as well as the workspace: the shell asks twice
        // per request, but a console command or a test may walk two people's
        // menus in one process and must not be handed the first one's.
        $key = $workspace.'|'.($user?->getKey() ?? 'guest');

        return $this->trees[$key] ??= $this->build($workspace, $user);
    }

    /**
     * The whole menu, unfiltered, for the screen that SETS the ticks.
     *
     * An administrator has to see the entries they are taking away, so this
     * one deliberately does not ask MenuAccessService anything.
     *
     * @return Collection<int, MenuSection>
     */
    public function rawTree(string $workspace): Collection
    {
        return $this->build($workspace, null);
    }

    /**
     * The unfiltered menu of one workspace, flattened in reading order with
     * the depth each entry sits at.
     *
     * The two screens that SET the ticks — one person, and the role matrix —
     * both want a list they can indent, not a nested structure they have to
     * recurse. Reading order matters: an administrator scans the list against
     * the menu they know, and a set that arrives grouped by parent id does not
     * look like the menu at all.
     *
     * @return array<int, array{section: MenuSection, item: MenuItem, depth: int}>
     */
    public function flatten(string $workspace): array
    {
        $rows = [];

        $walk = function (Collection $nodes, MenuSection $section, int $depth) use (&$walk, &$rows): void {
            foreach ($nodes as $node) {
                $rows[] = ['section' => $section, 'item' => $node, 'depth' => $depth];
                $walk($node->childItems ?? collect(), $section, $depth + 1);
            }
        };

        foreach ($this->rawTree($workspace) as $section) {
            $walk($section->items, $section, 1);
        }

        return $rows;
    }

    /** @return Collection<int, MenuSection> */
    protected function build(string $workspace, ?User $user): Collection
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

        /*
         * The access filter, one pass over the flat set before the tree is
         * built rather than a check inside the renderer. It has to be flat:
         * keeping a heading depends on whether anything under it survived, and
         * a per-node check in the blade can only see downwards one level at a
         * time. A person with no ticks anywhere is unrestricted and nothing is
         * removed — see MenuAccessService.
         */
        if ($user !== null) {
            $keep = $this->access->keepMap($user, $items);
            $items = $items->filter(fn (MenuItem $item): bool => $keep[(int) $item->Id] ?? false)->values();
        }

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
