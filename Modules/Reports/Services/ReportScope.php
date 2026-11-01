<?php

namespace Modules\Reports\Services;

/** URL parameters from the applied report scope, including rejected sites. */
final class ReportScope
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function query(array $params): array
    {
        return [
            'from' => $params['DateFrom'],
            'to' => $params['DateTo'],
            'branches' => $params['BranchIds'] === null ? null : array_map('intval', explode(',', $params['BranchIds'])),
            'q' => $params['Search'],
            'sort' => $params['SortColumn'],
            'dir' => $params['SortAsc'] ? 'asc' : 'desc',
        ];
    }
}
