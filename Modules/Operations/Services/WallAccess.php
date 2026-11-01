<?php

namespace Modules\Operations\Services;

use Modules\Core\Models\MenuItem;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;

/** A wall is another view of existing data, never a way around a source's menu tick. */
class WallAccess
{
    public function __construct(private MenuAccessService $menus) {}

    public function wall(User $user): bool
    {
        return $user->can('operations.wall.view') && $this->menus->canReachRoute($user, 'app.operations.wall');
    }

    public function report(User $user, string $slug): bool
    {
        $items = MenuItem::query()->acrossBranches()->where('IsActive', true)
            ->where('Url', '/app/reports/'.$slug)->get();

        return $items->contains(fn (MenuItem $item): bool => $this->menus->mayView($user, $item));
    }

    public function stock(User $user): bool
    {
        return $user->can('stockrecon.variance.view') && $this->menus->canReachRoute($user, 'app.stockrecon.variance');
    }

    public function cash(User $user): bool
    {
        return $user->can('cash.cashup.view') && $this->menus->canReachRoute($user, 'app.cash.banking');
    }

    /** @return array<int, string> */
    public function sources(User $user): array
    {
        $map = ['fuel' => 'fuel-input', 'meters' => 'pump-readings', 'shop' => 'day-close',
            'qsr' => 'day-close', 'budget-shop' => 'day-close', 'budget-qsr' => 'day-close',
            'budget-fuel' => 'fuel-input', 'loads' => 'stock-count'];
        $sources = [];
        foreach ($map as $key => $report) {
            if ($this->report($user, $report)) {
                $sources[] = $key;
            }
        }
        if ($this->stock($user)) {
            $sources[] = 'stock';
        }

        // Daily Banking moved from a report URL to Cash's working screen.
        // Its existing permission and menu tick must both allow this view.
        if ($this->cash($user)) {
            $sources[] = 'cash';
        }

        if ($user->can('master.stock.view') && $this->menus->canReachRoute($user, 'app.master.critical.index')) {
            $sources[] = 'critical';
        }

        return $sources;
    }
}
