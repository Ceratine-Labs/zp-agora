<?php

namespace App\Grid\Export;

use App\Grid\GridColumn;
use App\Grid\GridColumnView;
use App\Grid\GridDefinition;
use App\Grid\GridQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One extract: the rows, and the columns the user was looking at.
 *
 * Built by re-calling the SAME source with the SAME query the screen ran, with
 * the page size opened up to the ceiling. Not by re-implementing the filtering
 * in PHP, and not by paging round in a loop — the procedure already answers the
 * whole question, so asking it once for the whole answer is one round trip
 * rather than two hundred.
 *
 * feature-rules §3.2 is explicit that the file must be what the person is
 * looking at: their filters, their sort, their column order, their visible
 * columns. The first two come from the query, the last two from `columns`,
 * which the screen sends in the extract URL and which is checked here against
 * the catalogue — an unknown column name in the URL is dropped, not passed to
 * a writer that would then read a property nothing has.
 */
final class GridExtract
{
    /**
     * @param  array<int, GridColumn>  $columns  in the user's order, visible only
     * @param  Collection<int, object>  $rows
     */
    private function __construct(
        public GridDefinition $definition,
        public array $columns,
        public Collection $rows,
        public int $total,
        public float $ms,
    ) {}

    /**
     * @param  array<int, string>|null  $columnKeys  the visible columns, in the user's order;
     *                                               null falls back to the catalogue's defaults
     */
    public static function run(GridDefinition $definition, GridQuery $query, ?array $columnKeys = null): self
    {
        $ceiling = $definition->exportCeiling();
        $source = $definition->source();

        // Ask for the size of the answer before asking for the answer. One
        // extra call, and it is what stops a 400 000-row set being materialised
        // into memory before anybody discovers it is over the ceiling.
        $probe = $source->page($query->onPage(1));

        if ($probe->total > $ceiling) {
            throw new GridExportTooLarge($probe->total, $ceiling);
        }

        $page = $probe->total <= $query->pageSize && $query->page === 1
            ? $probe
            : $source->page($query->forExport(max(1, $probe->total)));

        return new self(
            definition: $definition,
            columns: self::columns($definition, $columnKeys),
            rows: $page->rows,
            total: $page->total,
            ms: $page->ms,
        );
    }

    /**
     * @param  array<int, string>|null  $keys
     * @return array<int, GridColumn>
     */
    private static function columns(GridDefinition $definition, ?array $keys): array
    {
        $catalogue = $definition->catalogue();

        if ($keys === null || $keys === []) {
            return array_values(array_filter($catalogue, fn (GridColumn $c) => $c->visible));
        }

        $columns = [];

        foreach ($keys as $key) {
            if (isset($catalogue[$key])) {
                $columns[$key] = $catalogue[$key];
            }
        }

        // A `columns=` list that named nothing real is a malformed state, and
        // §3.2 says a malformed state degrades to an unfiltered extract rather
        // than failing the download.
        return $columns === []
            ? array_values(array_filter($catalogue, fn (GridColumn $c) => $c->visible))
            : array_values($columns);
    }

    /** The columns as GridColumnView, for a caller that renders them. */
    /** @return array<int, GridColumnView> */
    public function views(): array
    {
        return array_map(fn (GridColumn $c) => new GridColumnView($c, true), $this->columns);
    }

    /** `day-close-status-2026-09-05.csv` — a name that says what it is in a downloads folder. */
    public function filename(string $extension): string
    {
        $slug = Str::slug($this->definition->title()) ?: 'extract';

        return $slug.'-'.now()->format('Y-m-d').'.'.$extension;
    }

    /** The value as it sits in the result set, untouched. */
    public function raw(object $row, GridColumn $column): mixed
    {
        $value = $row->{$column->key} ?? null;

        return $value === '' ? null : $value;
    }

    /**
     * One cell's value as a SPREADSHEET wants it, which is not what the screen
     * wants.
     *
     * A money column exports as 1234.5, not as "R1 234.50": the point of an
     * extract is that the receiving column adds up. Formatting is presentation
     * and it belongs on the screen; a figure that arrives in Excel as text is
     * a figure somebody has to clean before they can use it, which is the whole
     * reason people ask for the raw file.
     *
     * The exceptions are the columns that are not numbers pretending to be —
     * a chip, a boolean, a date — which export as the words a person reads.
     */
    public function value(object $row, GridColumn $column): string|float|null
    {
        $value = $row->{$column->key} ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ($column->isNumeric()) {
            return is_numeric($value) ? (float) $value : (string) $value;
        }

        if ($column->format === 'bool') {
            return $value ? 'Yes' : 'No';
        }

        if (in_array($column->format, ['date', 'datetime'], true)) {
            try {
                return Carbon::parse((string) $value)
                    ->format($column->format === 'date' ? 'Y-m-d' : 'Y-m-d H:i');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return (string) $value;
    }
}
