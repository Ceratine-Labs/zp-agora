<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Modules\Core\Models\MenuItem;
use Modules\Recon\Models\ReconRun;

/** Decorative identity only: no navigation, permission or database decisions. */
class NavigationArtwork
{
    private const MODULES = ['cup', 'cart', 'bank', 'utilities', 'cashup', 'reports', 'operations', 'product', 'assets', 'debtors', 'settings', 'my-queue'];

    private const FALLBACK = ['kind' => 'module', 'variant' => 'cup'];

    private const RECON_AREAS = [
        'ABSA' => 'bank-absa', 'FNB' => 'bank-fnb',
        'CashBags' => 'bank-cash-bags', 'CashMachine' => 'bank-cash-machine', 'SmartATM' => 'bank-smart-atm',
    ];

    /** Specific tools precede their module; detail/phone pages keep that tool's identity. */
    private const TOOLS = [
        'app.cash.cashups.nocashup' => 'cash-no-cashup',
        'app.cash.dropsafe.bags' => 'cash-safe-bags',
        'app.cash.staffshorts.list' => 'cash-shorts-list',
        'app.cash.deductions.stockloss*' => 'cash-stock-loss',
        'app.cash.deductions.nonint*' => 'cash-non-integrated',
        'app.cash.deductions.payroll' => 'cash-payroll',
        'app.cash.zread*' => 'cash-allocation',
        'app.cash.banking*' => 'cash-banking',
        'app.cash.cashups*' => 'cashups',
        'app.cash.tillbalance*' => 'till-balance',
        'app.cash.pumpshortages' => 'pump-shortages',
        'app.cash.dropsafe*' => 'cash-safe',
        'app.cash.staffshorts*' => 'cash-staff-shorts',
        'app.cash.deductions*' => 'cash-deductions',
        'app.recon.dashboard' => 'bank-dashboard',
        'app.recon.trace' => 'bank-trace',
        'app.recon.*' => 'bank-reconcile',
        'app.stockrecon.weekly*' => 'stock-weekly',
        'app.stockrecon.variance' => 'stock-variance',
        'app.stockrecon.countingareas*' => 'stock-areas',
        'app.stockrecon.water' => 'stock-water',
        'app.stockrecon.*' => 'stock-centre',
        'app.master.stock.*' => 'product-stock',
        'app.master.critical.*' => 'product-critical',
        'app.setup.users.*' => 'access-users',
        'app.setup.roles.*' => 'access-roles',
        'app.debtors.*' => 'debtor-statements',
        'app.assets.register*' => 'asset-register',
        'app.assets.movement*' => 'asset-movement',
        'app.assets.repairs*' => 'asset-repairs',
        'app.assets.masters' => 'asset-masters',
        'app.utilities.*' => 'utility-readings',
    ];

    /** Stable menu identities also cover headings and future destinations. */
    private const PATH_ART = [
        'operations' => ['operations-wall', 'the-group', 'how-we-are-doing', 'scorecard', 'site-rating'],
        'utilities' => ['utilities', 'meters', 'utility-reading', 'utility-readings'],
        'bank' => ['bank-recon', 'recon', 'banking-exceptions', 'settlement', 'money'],
        'cart' => ['stock-recon', 'stockrecon', 'counting', 'count-exceptions', 'balancing', 'area-locks', 'month-end', 'stock-variance', 'counting-areas'],
        'cashup' => ['cashups', 'cash-bags', 'deductions', 'zread', 'cashback', 'cash-banking', 'close-the-day'],
        'product' => ['products', 'products-stock', 'critical-lines', 'production', 'pre-production', 'butchery', 'profit-centre', 'profit-centres', 'fuel', 'shop', 'ok-stores', 'qsr', 'lubes', 'virtual', 'categories', 'standard-categories', 'trading-rules', 'fuel-matrix', 'suppliers', 'sales-by-product'],
        'debtors' => ['debtors', 'account-customers'],
        'assets' => ['assets', 'equipment', 'movement', 'repairs'],
        'settings' => ['setup', 'business', 'people-assets', 'employees', 'users', 'roles', 'branches', 'expense-codes'],
        'reports' => ['reports', 'the-day', 'decisions', 'capture', 'exceptions', 'governance', 'raise', 'exco-pack', 'exco', 'trading-position', 'league-table', 'budget-actual', 'sales', 'margin', 'margin-breaks', 'pump-checks', 'load-errors'],
    ];

    /** @var array<string, array{kind: string, variant: string}> */
    private array $urls = [];

    public function __construct(protected Router $router) {}

    /** @return array{kind: string, variant: string} */
    public function current(): array
    {
        $route = request()->route();

        return $route instanceof Route ? $this->forRoute($route->getName(), $route->parameters()) : self::FALLBACK;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{kind: string, variant: string}
     */
    public function forRoute(?string $name, array $parameters = []): array
    {
        if ($name === 'app.dashboard') {
            return ['kind' => 'logo', 'variant' => 'zp'];
        }
        if ($name === 'app.modules.show') {
            $key = $parameters['module'] ?? null;
            $modules = config('agora.landing_modules', []);

            return is_string($key) && isset($modules[$key]) ? $this->module($modules[$key]['art']) : self::FALLBACK;
        }
        foreach (config('reports.reports', []) as $key => $report) {
            if (($name === 'app.reports.show' && ($parameters['report'] ?? null) === $key)
                || ($name !== null && ($report['detail']['route'] ?? null) === $name)) {
                return ['kind' => 'report', 'variant' => $key];
            }
        }
        // The run page already holds an authorized, bound model. Its saved
        // type supplies the title and startup art without another read. A raw
        // run id in a menu URL deliberately stays generic.
        $run = $parameters['run'] ?? null;
        if ($name !== null && str_starts_with($name, 'app.recon.') && $run instanceof ReconRun
            && in_array('run', $this->router->getRoutes()->getByName($name)?->parameterNames() ?? [], true)
            && isset(self::RECON_AREAS[$run->ReconArea])) {
            return ['kind' => 'tool', 'variant' => self::RECON_AREAS[$run->ReconArea]];
        }
        $area = $parameters['area'] ?? null;
        if ($name !== null && str_starts_with($name, 'app.recon.')
            && is_string($area) && isset(self::RECON_AREAS[$area])
            && in_array('area', $this->router->getRoutes()->getByName($name)?->parameterNames() ?? [], true)) {
            return ['kind' => 'tool', 'variant' => self::RECON_AREAS[$area]];
        }
        foreach (self::TOOLS as $pattern => $variant) {
            if ($name !== null && Str::is($pattern, $name)) {
                return ['kind' => 'tool', 'variant' => $variant];
            }
        }
        foreach (config('agora.route_loaders', []) as $match) {
            if ($name !== $match['route']) {
                continue;
            }
            foreach ($match['parameters'] ?? [] as $key => $value) {
                if (($parameters[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $this->module($match['art']);
        }
        foreach (config('agora.loaders', []) as $pattern => $variant) {
            if ($name !== null && Str::is($pattern, $name)) {
                return $this->module($variant);
            }
        }

        return self::FALLBACK;
    }

    /** @return array{kind: string, variant: string} */
    public function forItem(MenuItem $item): array
    {
        return $this->itemArt($item) ?? ['kind' => 'module', 'variant' => 'reports'];
    }

    /** @return array{kind: string, variant: string}|null */
    private function itemArt(MenuItem $item): ?array
    {
        if ($item->availableHref && ($art = $this->forUrl($item->availableHref)) !== self::FALLBACK) {
            return $art;
        }
        if (($art = $this->forRoute($item->RouteName)) !== self::FALLBACK) {
            return $art;
        }
        if ($item->Url && ($art = $this->forUrl($item->Url)) !== self::FALLBACK) {
            return $art;
        }
        $segments = explode('/', trim((string) $item->Path, '/'));
        $leaf = end($segments);
        $aliases = ['staff-short' => 'staff-shorts', 'purchase-request' => 'purchase-approvals', 'utility-reading' => 'utility-meters', 'stock-counts' => 'stock-count'];
        foreach (config('reports.reports', []) as $key => $report) {
            if (($aliases[$leaf] ?? $leaf) === $key || Str::endsWith('/'.$item->Path, '/'.($report['menu'] ?? $key))) {
                return ['kind' => 'report', 'variant' => $key];
            }
        }
        // The closest path segment wins: equipment/repairs is assets even
        // when nested beneath a broad setup or reporting heading.
        foreach (array_reverse($segments) as $segment) {
            foreach (self::PATH_ART as $variant => $paths) {
                if (in_array($segment, $paths, true)) {
                    return $this->module($variant);
                }
            }
        }
        foreach ($item->childItems ?? [] as $child) {
            if (($art = $this->itemArt($child)) !== null) {
                return $art;
            }
        }

        return null;
    }

    /** @return array{kind: string, variant: string} */
    public function forUrl(?string $url): array
    {
        $url = trim($url ?? '');
        if (! $this->isInternalUrl($url)) {
            return self::FALLBACK;
        }
        if (isset($this->urls[$url])) {
            return $this->urls[$url];
        }
        $request = Request::create(url($url), 'GET');
        foreach ($this->router->getRoutes()->get('GET') as $candidate) {
            if ($candidate->matches($request)) {
                // Extract URL parameters on a copy; leave the active request
                // and registered route untouched, with no model binding.
                $route = (clone $candidate)->bind($request);

                return $this->urls[$url] = $this->forRoute($route->getName(), $route->parameters());
            }
        }

        return $this->urls[$url] = self::FALLBACK;
    }

    private function isInternalUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || str_starts_with($url, '//')
            || (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
            return false;
        }
        if (isset($parts['host'])) {
            $origin = parse_url(url('/'));

            return strtolower($parts['host']) === strtolower($origin['host'] ?? '')
                && ($parts['port'] ?? null) === ($origin['port'] ?? null);
        }

        return ! isset($parts['scheme']) && str_starts_with($url, '/');
    }

    /** @return array{kind: string, variant: string} */
    private function module(string $variant): array
    {
        return ['kind' => 'module', 'variant' => in_array($variant, self::MODULES, true) ? $variant : 'cup'];
    }
}
