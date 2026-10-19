<?php

namespace Modules\StockRecon\Services;

use App\Grid\GridResult;
use App\Support\Format;
use Illuminate\Support\Collection;

/** Presents the run's type inventory using the grid's existing URL filters. */
final class ExceptionNavigation
{
    /**
     * @param  Collection<int, object>  $counts
     * @return array{items: array<int, array<string, mixed>>, active: string, help: array<string, mixed>|null}
     */
    public function build(GridResult $grid, Collection $counts): array
    {
        $columns = ['ExceptionCode' => 'exceptions', 'AuditCode' => 'audit'];
        $filters = $grid->query[$grid->param('f')] ?? [];
        $filters = is_array($filters) ? $filters : [];
        unset($filters['ExceptionCode'], $filters['AuditCode']);

        $selected = [];
        foreach ($grid->gridQuery->filters as $filter) {
            if (isset($columns[$filter['column']])) {
                foreach ($filter['in'] ?? [] as $code) {
                    $selected[] = $filter['column'].':'.$code;
                }
            }
        }

        $active = match (count($selected)) {
            0 => 'all',
            1 => $selected[0],
            default => 'custom',
        };
        $url = fn (array $filters): string => $grid->urlWith([
            $grid->param('f') => $filters,
            $grid->param('page') => null,
        ]).'#exception-types';
        $items = [[
            'key' => 'all', 'label' => 'All types',
            'count' => Format::n($counts->firstWhere('Kind', 'all')->TotalRows ?? 0),
            'href' => $url($filters),
        ]];
        $help = null;

        foreach ($columns as $column => $config) {
            $available = $counts->where('Kind', $column)->keyBy('Code');
            $definitions = (array) config('stockrecon.'.$config);
            // Keep the configured severity order, including historic codes
            // still present on a run after their definition has been retired.
            $codes = array_unique(array_merge(array_keys($definitions), $available->keys()->all()));
            foreach ($codes as $code) {
                if (! isset($available[$code])) {
                    continue;
                }
                $definition = $definitions[$code] ?? [];
                $key = $column.':'.$code;
                $label = $code.' · '.($definition['label'] ?? 'Unrecognised code');
                $items[] = [
                    'key' => $key, 'label' => $label,
                    'count' => Format::n($available[$code]->TotalRows),
                    'tone' => $definition['tone'] ?? 'neutral',
                    'group' => $column === 'ExceptionCode' ? 'Count exceptions' : 'Hand-balancing checks',
                    'href' => $url($filters + [$column => ['in' => [$code]]]),
                ];
                if ($key === $active) {
                    $help = ['title' => $label] + $definition;
                }
            }
        }

        return ['items' => $items, 'active' => $active, 'help' => $help];
    }
}
