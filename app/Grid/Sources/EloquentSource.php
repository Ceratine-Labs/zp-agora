<?php

namespace App\Grid\Sources;

use App\Grid\GridPage;
use App\Grid\GridQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A grid over a query builder.
 *
 * For the grids that are MACHINERY rather than exposure (feature-rules §2):
 * a development surface, an internal list of Agora's own rows, anything the
 * customer is not meant to open in SSMS and change. Wrapping one of those in
 * T-SQL satisfies the letter of the procedure rule and none of its purpose.
 *
 * Paging is server-side here too — `count()` for the size of the filtered set,
 * then `offset`/`limit` for the page. Two queries rather than one, which is the
 * usual arrangement and is why the procedure contract returns the total as a
 * second result set instead: same two answers, one round trip.
 *
 * The filter semantics live here and nowhere else. That is the same rule the
 * procedure source follows, pointed at the other engine: one implementation of
 * "contains" per source, never one in the source and a second in PHP.
 */
final class EloquentSource implements GridSource
{
    /**
     * @param  \Closure(): Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  a fresh builder each call — a
     *                                                                                     shared one accumulates the last request's where clauses
     * @param  array<int, string>  $searchable  columns the grid's global search looks in
     * @param  array<string, string>  $sortable  sort value => column to ORDER BY
     */
    public function __construct(
        private \Closure $query,
        private array $searchable = [],
        private array $sortable = [],
    ) {}

    public function page(GridQuery $query): GridPage
    {
        $started = microtime(true);

        $builder = ($this->query)();
        $this->applySearch($builder, $query);
        $this->applyFilters($builder, $query);

        $total = (clone $builder)->toBase()->getCountForPagination();

        $this->applySort($builder, $query);

        return new GridPage(
            rows: new Collection($this->plain($builder->forPage($query->page, $query->pageSize)->get())),
            total: $total,
            ms: (microtime(true) - $started) * 1000,
        );
    }

    /**
     * The rows as plain objects, keyed by position.
     *
     * The grid works in plain objects because that is what a procedure returns,
     * and the cell partial must not be able to tell which source it is looking
     * at. Built as an ARRAY and wrapped, rather than mapped over the collection:
     * a Collection's value type is invariant, so a Collection<int, stdClass>
     * cannot be handed to something expecting Collection<int, object> — an
     * array can, and this is the one place the conversion happens.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model>  $models
     * @return array<int, object>
     */
    private function plain($models): array
    {
        $rows = [];

        foreach ($models as $model) {
            $rows[] = (object) $model->attributesToArray();
        }

        return $rows;
    }

    public function name(): ?string
    {
        return null;
    }

    public function supportsFilters(): bool
    {
        return true;
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder */
    private function applySearch(Builder $builder, GridQuery $query): void
    {
        if ($query->search === null || $this->searchable === []) {
            return;
        }

        $builder->where(function (Builder $group) use ($query) {
            foreach ($this->searchable as $column) {
                $group->orWhere($column, 'like', '%'.$query->search.'%');
            }
        });
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder */
    private function applyFilters(Builder $builder, GridQuery $query): void
    {
        foreach ($query->filters as $filter) {
            /** @var string $column */
            $column = $filter['column'];

            if (($filter['type'] ?? null) === 'set') {
                /** @var array<int, string> $values */
                $values = $filter['in'] ?? [];
                $builder->whereIn($column, $values);

                continue;
            }

            /** @var string $value */
            $value = $filter['value'] ?? '';
            /** @var string $operator */
            $operator = $filter['op'] ?? 'contains';

            match ($operator) {
                'contains' => $builder->where($column, 'like', '%'.$value.'%'),
                'ne' => $builder->where($column, '!=', $value),
                'gt' => $builder->where($column, '>', $value),
                'gte' => $builder->where($column, '>=', $value),
                'lt' => $builder->where($column, '<', $value),
                'lte' => $builder->where($column, '<=', $value),
                default => $builder->where($column, '=', $value),
            };
        }
    }

    /**
     * Sort by the catalogue's value, mapped to a real column.
     *
     * The map is what stops a sort value from the query string reaching
     * `orderBy` as a column name. GridService has already checked it against
     * the catalogue; this is the second gate, and the one that would still
     * hold if the first were bypassed.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder
     */
    private function applySort(Builder $builder, GridQuery $query): void
    {
        $column = $query->sort === null ? null : ($this->sortable[$query->sort] ?? null);

        if ($column === null) {
            return;
        }

        $builder->orderBy($column, $query->ascending ? 'asc' : 'desc');
    }
}
