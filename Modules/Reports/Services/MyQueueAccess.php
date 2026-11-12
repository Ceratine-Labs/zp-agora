<?php

namespace Modules\Reports\Services;

use Modules\Core\Models\User;

/** Independently assignable event permissions can be granted through roles. */
final class MyQueueAccess
{
    public const TYPES = [
        'day-close' => 'Day close',
        'unallocated-zreads' => 'Z-reads',
        'open-cashups' => 'Cashups',
        'purchase-approvals' => 'Purchases',
        'staff-shorts' => 'Staff shorts',
        'drop-safe' => 'Drop safe',
        'waste-approvals' => 'Waste authorization',
    ];

    /** @return array<int, string> */
    public function allowed(User $user): array
    {
        return array_values(array_filter(array_keys(self::TYPES),
            fn (string $key): bool => $user->can('reports.queue-'.$key.'.view')));
    }
}
