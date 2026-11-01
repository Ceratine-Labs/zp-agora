<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\ModuleMenuController;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;

/**
 * Module hubs are another presentation of the person's permitted menu.
 *
 * @phpstan-type Tool array{label: string, description: string, href: string, art: array{kind: string, variant: string}, path: string, route: string, parameters: array<string, mixed>}
 * @phpstan-type Group array{key: string, label: string, tools: list<Tool>}
 * @phpstan-type Hub array{key: string, label: string, description: string, art: string, href: string, groups: list<Group>}
 */
class ModuleMenu
{
    public function __construct(
        protected MenuService $menu,
        protected MenuAvailability $availability,
        protected NavigationModules $modules,
        protected NavigationArtwork $artwork,
    ) {}

    /** @return array<string, Hub> */
    public function forUser(User $user, string $workspace): array
    {
        $definitions = config('agora.landing_modules', []);
        $hubs = [];
        $seen = [];
        foreach ($this->menu->tree($workspace, $user) as $section) {
            foreach ($this->destinations($section->items, $user) as [$item, $route, $href, $heading]) {
                $bound = (clone $route)->bind(Request::create(url($href), 'GET'));
                $parameters = $bound->parameters();
                // A copy carries the just-authorized destination for the
                // semantic/art resolvers; the shared menu remains untouched.
                $toolItem = clone $item;
                $toolItem->availableHref = $href;
                $identity = $this->modules->forRoute($route->getName(), $parameters)
                    ?? $this->modules->forDestination($toolItem);
                $key = $identity['key'];
                if (! isset($definitions[$key])) {
                    continue;
                }
                $href = $this->withWorkspace($href, $workspace);
                $destination = $this->destinationKey($href);
                if (isset($seen[$key][$destination])) {
                    continue;
                }
                $seen[$key][$destination] = true;
                $definition = $definitions[$key];
                $hubs[$key] ??= [
                    'key' => $key, 'label' => $definition['label'], 'description' => $definition['description'],
                    'art' => $definition['art'], 'href' => route('app.modules.show', ['module' => $key, 'ws' => $workspace]),
                    'groups' => [],
                ];
                $groupKey = (string) $section->Id.':'.($heading?->Path ?: $heading?->Id ?: 'root');
                $groupLabel = $section->Label.($heading === null ? '' : ' · '.$heading->Label);
                $hubs[$key]['groups'][$groupKey] ??= ['key' => $groupKey, 'label' => $groupLabel, 'tools' => []];
                $hubs[$key]['groups'][$groupKey]['tools'][] = [
                    'label' => $item->Label, 'description' => $this->description($item, $bound),
                    'href' => $href, 'art' => $this->artwork->forItem($toolItem), 'path' => (string) $item->Path,
                    'route' => (string) $route->getName(), 'parameters' => $parameters,
                ];
            }
        }

        $ordered = [];
        foreach (array_keys($definitions) as $key) {
            if (isset($hubs[$key])) {
                $hubs[$key]['groups'] = array_values($hubs[$key]['groups']);
                $ordered[$key] = $hubs[$key];
            }
        }

        return $ordered;
    }

    /** @return Hub|null */
    public function findForUser(User $user, string $workspace, string $module): ?array
    {
        if (! array_key_exists($module, config('agora.landing_modules', []))) {
            return null;
        }

        return $this->forUser($user, $workspace)[$module] ?? null;
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return \Generator<int, array{MenuItem, Route, string, ?MenuItem}>
     */
    private function destinations(Collection $items, User $user, ?MenuItem $heading = null): \Generator
    {
        foreach ($items as $item) {
            $children = $item->childItems ?? collect();
            $context = $children->isNotEmpty() ? $item : $heading;
            $destination = $this->availability->destination($item, $user);
            if ($destination !== null && ! in_array($destination[0]->getControllerClass(), [DashboardController::class, ModuleMenuController::class], true)) {
                yield [$item, $destination[0], $destination[1], $context];
            }
            yield from $this->destinations($children, $user, $context);
        }
    }

    private function description(MenuItem $item, Route $route): string
    {
        $report = $route->parameter('report');
        if ($route->getName() === 'app.reports.show' && is_string($report)) {
            $definition = config('reports.reports', [])[$report] ?? [];
            if (isset($definition['summary'])) {
                return $definition['summary'];
            }
        }
        $hint = trim((string) $item->Hint);

        return strcasecmp($hint, 'Live') === 0 ? '' : $hint;
    }

    private function withWorkspace(string $href, string $workspace): string
    {
        [$target, $fragment] = array_pad(explode('#', $href, 2), 2, null);
        $url = Request::create(url($target), 'GET')->fullUrlWithQuery(['ws' => $workspace]);

        return $url.($fragment === null ? '' : '#'.$fragment);
    }

    private function destinationKey(string $href): string
    {
        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);

        return hash('sha256', serialize([$parts['path'] ?? '/', $this->orderedQuery($query), $parts['fragment'] ?? null]));
    }

    /**
     * @param  array<string|int, mixed>  $query
     * @return array<string|int, mixed>
     */
    private function orderedQuery(array $query): array
    {
        ksort($query);
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->orderedQuery($value);
            }
        }

        return $query;
    }
}
