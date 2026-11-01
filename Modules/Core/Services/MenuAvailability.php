<?php

namespace Modules\Core\Services;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\User;

/** Working destinations shared by the app menu and the module landing page. */
class MenuAvailability
{
    public function __construct(
        protected MenuAccessService $access,
        protected Router $router,
    ) {}

    /**
     * The item's own accessible GET destination. An ancestor kept by the menu
     * filter solely for a child does not gain access to its own link.
     *
     * @return array{Route, string}|null
     */
    public function destination(MenuItem $item, User $user): ?array
    {
        $destination = $this->resolve($item);
        if ($destination === null || ! $this->access->mayView($user, $item)) {
            return null;
        }
        [$route] = $destination;
        if (! $this->permitsRoute($user, $route)) {
            return null;
        }
        $name = (string) $route->getName();
        // A stored URL may point at a route whose separately named menu
        // entries are restricted by EnforceMenuAccess.
        if ((! $item->RouteName || $item->RouteName !== $name) && ! $this->access->canReachRoute($user, $name)) {
            return null;
        }

        return $destination;
    }

    /**
     * Mark the existing permitted catalogue without removing any nodes. The
     * browser can show inactive entries again without another request.
     *
     * @param  Collection<int, MenuSection>  $sections
     * @return Collection<int, MenuSection>
     */
    public function annotate(Collection $sections, User $user): Collection
    {
        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $this->annotateItem($item, $user);
            }
        }

        return $sections;
    }

    protected function annotateItem(MenuItem $item, User $user): void
    {
        $destination = $this->destination($item, $user);
        $item->availableHref = $destination[1] ?? null;
        $item->hasAvailableItems = $item->availableHref !== null;
        foreach ($item->childItems ?? [] as $child) {
            $this->annotateItem($child, $user);
            $item->hasAvailableItems = $item->hasAvailableItems || $child->hasAvailableItems;
        }
    }

    /** @return array{Route, string}|null */
    protected function resolve(MenuItem $item): ?array
    {
        $route = $item->RouteName ? $this->router->getRoutes()->getByName($item->RouteName) : null;
        if ($route !== null) {
            try {
                $href = route($item->RouteName);
            } catch (UrlGenerationException) {
                // A record route without its required parameters is not a
                // module landing, and must not break the whole dashboard.
                return null;
            }
        } else {
            $href = trim((string) $item->Url);
            if ($href === '' || ! $this->isInternalUrl($href)) {
                return null;
            }
            $request = Request::create(url($href), 'GET');
            // Match without dispatching or rebinding the application's route
            // objects. Stored report/recon URLs carry their own parameters.
            foreach ($this->router->getRoutes()->get('GET') as $candidate) {
                if ($candidate->matches($request)) {
                    $route = $candidate;
                    break;
                }
            }
        }

        if ($route === null || ! in_array('GET', $route->methods(), true) || ! Str::startsWith((string) $route->getName(), 'app.')
            || in_array($route->getControllerClass(), [LandingStubController::class], true)) {
            return null;
        }

        return [$route, $href];
    }

    protected function isInternalUrl(string $href): bool
    {
        $parts = parse_url($href);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || str_starts_with($href, '//')) {
            return false;
        }
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['host'])) {
            $origin = parse_url(url('/'));

            return strtolower($parts['host']) === strtolower($origin['host'] ?? '')
                && ($parts['port'] ?? null) === ($origin['port'] ?? null);
        }

        return ! isset($parts['scheme']) && str_starts_with($href, '/');
    }

    protected function permitsRoute(User $user, Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, ['can:', Authorize::class.':'])) {
                continue;
            }
            $ability = Str::after($middleware, ':');
            // Module entry permissions are plain abilities. A policy requiring
            // a bound record cannot be safely decided from a menu shortcut.
            if (str_contains($ability, ',') || ! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }
}
