<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Core\Models\MenuItem;

/** Labels within the already permitted workflow tree, without changing it. */
class NavigationModules
{
    private const LABELS = ['operations' => 'Operations', 'cash' => 'Cashups', 'recon' => 'Bank recon', 'stockrecon' => 'Stock recon'];

    private const REPORT_MODULES = [
        'my-queue' => 'operations',
        'unallocated-zreads' => 'cash', 'open-cashups' => 'cash', 'staff-shorts' => 'cash', 'drop-safe' => 'cash', 'daily-banking' => 'cash',
        'stock-count' => 'stockrecon', 'utility-meters' => 'utilities',
    ];

    /** Stable seeded paths identify entries whose screens are still unbuilt. */
    private const PATH_MODULES = [
        'operations' => ['operations-wall', 'my-day', 'my-queue'],
        'cash' => ['cashups', 'zread', 'zreads-no-cashup', 'daily-banking', 'banking-exceptions', 'cash-bags', 'drop-safe', 'drop-safe-bags', 'staff-short', 'staff-shorts', 'deductions', 'till-balance', 'pump-shortages', 'cashback', 'cash-banking'],
        'recon' => ['bank-recon', 'recon', 'recon-dashboard', 'recon-trace', 'settlement'],
        'stockrecon' => ['stock', 'stock-recon', 'stockrecon', 'recon-centre', 'counting', 'stock-count', 'stock-counts', 'stock-variance', 'count-exceptions', 'balancing', 'area-locks', 'month-end', 'counting-areas'],
        'utilities' => ['utilities', 'meters', 'utility-meters', 'utility-reading', 'utility-readings'],
        'products' => ['products', 'critical-lines', 'categories', 'standard-categories', 'fuel-matrix', 'suppliers', 'production', 'pre-production', 'butchery'],
        'debtors' => ['debtors', 'debtors-statements', 'account-customers'],
        'assets' => ['assets', 'equipment', 'asset-movement', 'asset-repairs', 'asset-masters'],
        'access' => ['users', 'roles', 'employees', 'branches', 'profit-centres', 'expense-codes'],
    ];

    public function __construct(protected Router $router) {}

    /**
     * Group only this parent's direct leaves. Existing nested headings stay
     * intact as standalone blocks, to be rendered recursively by the caller.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, array{key: ?string, label: ?string, items: Collection<int, MenuItem>}>
     */
    public function groups(Collection $items): array
    {
        $groups = [];
        $positions = [];
        foreach ($items as $item) {
            $module = $this->forItem($item);
            if ($module === null) {
                $groups[] = ['key' => null, 'label' => null, 'items' => collect([$item])];

                continue;
            }
            if (! isset($positions[$module['key']])) {
                $positions[$module['key']] = count($groups);
                $groups[] = $module + ['items' => collect([$item])];
            } else {
                $groups[$positions[$module['key']]]['items']->push($item);
            }
        }

        return $groups;
    }

    /** @return array{key: string, label: string}|null */
    public function forItem(MenuItem $item): ?array
    {
        if ($item->childItems?->isNotEmpty()) {
            return null;
        }

        return $this->forDestination($item);
    }

    /** The label for a nested heading's own Overview/Open link.
     *
     * @return array{key: string, label: string}
     */
    public function forDestination(MenuItem $item): array
    {
        if ($item->availableHref && ($key = $this->fromUrl($item->availableHref)) !== null) {
            return $this->module($key);
        }
        // The named destination wins over old URL/path metadata, except that
        // a parameterised report needs its URL or stable path to identify it.
        if ($item->RouteName !== 'app.reports.show' && ($key = $this->fromRoute($item->RouteName)) !== null) {
            return $this->module($key);
        }
        if ($item->Url && ($key = $this->fromUrl($item->Url)) !== null) {
            return $this->module($key);
        }
        if (($key = $this->fromPath((string) $item->Path)) !== null) {
            return $this->module($key);
        }

        return $this->module($this->fromRoute($item->RouteName) ?? 'other');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{key: string, label: string}|null
     */
    public function forRoute(?string $route, array $parameters = []): ?array
    {
        $key = $this->fromRoute($route, $parameters);

        return $key === null ? null : $this->module($key);
    }

    /** @param array<string, mixed> $parameters */
    private function fromRoute(?string $route, array $parameters = []): ?string
    {
        if ($route === 'app.dashboard') {
            return 'operations';
        }
        if ($route === null) {
            return null;
        }
        if ($route === 'app.modules.show') {
            $key = $parameters['module'] ?? null;

            return is_string($key) && array_key_exists($key, config('agora.landing_modules', [])) ? $key : null;
        }
        if ($route === 'app.reports.show' && is_string($parameters['report'] ?? null)
            && array_key_exists($parameters['report'], config('reports.reports', []))) {
            return self::REPORT_MODULES[$parameters['report']] ?? 'reports';
        }
        foreach (config('agora.landing_modules', []) as $key => $module) {
            foreach ($module['route_prefixes'] ?? [] as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return $key;
                }
            }
            if (in_array($route, $module['route_names'] ?? [], true)) {
                foreach ($module['route_parameters'] ?? [] as $parameter => $value) {
                    if (($parameters[$parameter] ?? null) !== $value) {
                        continue 2;
                    }
                }

                return $key;
            }
        }

        return null;
    }

    private function fromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || str_starts_with($url, '//')
            || (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
            return null;
        }
        if (isset($parts['host'])) {
            $origin = parse_url(url('/'));
            if (strtolower($parts['host']) !== strtolower($origin['host'] ?? '') || ($parts['port'] ?? null) !== ($origin['port'] ?? null)) {
                return null;
            }
        } elseif (isset($parts['scheme']) || ! str_starts_with($url, '/')) {
            return null;
        }
        $request = Request::create(url($url), 'GET');
        foreach ($this->router->getRoutes()->get('GET') as $candidate) {
            if ($candidate->matches($request)) {
                $route = (clone $candidate)->bind($request);

                return $this->fromRoute($route->getName(), $route->parameters());
            }
        }

        return null;
    }

    private function fromPath(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));
        $leaf = Str::afterLast(trim($path, '/'), '/');
        $aliases = ['staff-short' => 'staff-shorts', 'purchase-request' => 'purchase-approvals', 'utility-reading' => 'utility-meters'];
        $report = $aliases[$leaf] ?? $leaf;
        if (array_key_exists($report, config('reports.reports', []))) {
            return self::REPORT_MODULES[$report] ?? 'reports';
        }
        foreach (array_reverse($segments) as $segment) {
            foreach (self::PATH_MODULES as $key => $paths) {
                if (in_array($segment, $paths, true)) {
                    return $key;
                }
            }
        }

        if (array_intersect($segments, ['reports', 'the-group', 'profit-centre', 'how-we-are-doing', 'exceptions', 'governance']) !== []) {
            return 'reports';
        }

        return null;
    }

    /** @return array{key: string, label: string} */
    private function module(string $key): array
    {
        return ['key' => $key, 'label' => self::LABELS[$key] ?? config('agora.landing_modules.'.$key.'.label', 'Other')];
    }
}
