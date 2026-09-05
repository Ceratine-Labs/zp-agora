<?php

namespace App\Grid;

/**
 * One column in a grid's catalogue.
 *
 * The catalogue is the single declaration of what a column IS — its heading,
 * how its value is written, whether it can be sorted and by what, whether it
 * is shown before the user has said otherwise. Everything downstream reads it:
 * the header, the cell partial, the header filter, the export, the column
 * chooser and the footer total. Declaring the same fact twice is how a grid
 * ends up exporting different numbers from the ones on the screen.
 *
 * `format` is the vocabulary from the build plan (T014) — R / Rk / Lk / pct /
 * date / chip and the rest — and it decides three things at once: the
 * alignment, which App\Support\Format call writes the value, and which kind of
 * header filter the column gets. A column is numeric because of its format,
 * never because a view remembered to add a class.
 *
 * `sort` is the value handed to the procedure as @SortColumn, or the column to
 * ORDER BY for an Eloquent source. It is deliberately separate from `key`: a
 * result set commonly exposes one name while the procedure sorts on another,
 * and a column with no sort value is one the source cannot order by — which
 * the header then renders as plain text rather than as a link that quietly
 * does nothing.
 */
final class GridColumn
{
    /** Formats whose values are numbers, and so right-align and sort numerically. */
    public const NUMERIC_FORMATS = ['number', 'money', 'rk', 'lk', 'litres', 'cpl', 'pct', 'delta'];

    /** Every format the cell partial knows how to write. */
    public const FORMATS = [
        'text', 'mono', 'chip', 'bool', 'date', 'datetime',
        'number', 'money', 'rk', 'lk', 'litres', 'cpl', 'pct', 'delta',
    ];

    /**
     * @param  string  $key  the column in the result set, or the model attribute
     * @param  string  $label  the heading, as a person reads it
     * @param  string  $format  one of self::FORMATS
     * @param  string|null  $sort  the @SortColumn value, or null when the source cannot order by it
     * @param  bool  $visible  shown before the user has chosen otherwise
     * @param  bool  $wide  prose: wraps, and claims a minimum width
     * @param  bool  $mono  rendered in the mono face — a reference, a code, an id
     * @param  string|null  $filter  text|number|date|set, or null to derive it from the format
     * @param  array<int, string>  $options  the tick-list for a set filter, capped at GridFilter::MAX_SET
     * @param  bool  $total  carried into the footer total row
     * @param  string|null  $title  the header's tooltip, where the label alone is not enough
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $format = 'text',
        public ?string $sort = null,
        public bool $visible = true,
        public bool $wide = false,
        public bool $mono = false,
        public ?string $filter = null,
        public array $options = [],
        public bool $total = false,
        public ?string $title = null,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException(
                "Unknown column format [{$format}] on [{$key}]. One of: ".implode(', ', self::FORMATS).'.'
            );
        }
    }

    /** Right-aligned, tabular, and sorted as a number rather than as text. */
    public function isNumeric(): bool
    {
        return in_array($this->format, self::NUMERIC_FORMATS, true);
    }

    public function isSortable(): bool
    {
        return $this->sort !== null;
    }

    /**
     * Which header filter this column gets, when it has not been told.
     *
     * The control matches what the cell renders (feature-rules §3.1): a number
     * gets comparators, a date gets a range, a chip gets the Excel-style tick
     * list, and everything else gets contains/equals.
     */
    public function filterType(): string
    {
        if ($this->filter !== null) {
            return $this->filter;
        }

        return match (true) {
            $this->isNumeric() => 'number',
            in_array($this->format, ['date', 'datetime'], true) => 'date',
            in_array($this->format, ['chip', 'bool'], true) => 'set',
            default => 'text',
        };
    }

    /**
     * The classes the shell puts on this column's cells.
     *
     * `num` and `mono` are `<x-table>`'s own — `table.dt td.num` is what
     * right-aligns a figure and gives it tabular numerals, and the grid composes
     * that table rather than restating it. The mockup's `dataGrid` called them
     * `n` and `w`; using those here produced numeric cells that were left
     * aligned in a stylesheet that had no rule for them.
     *
     * @return array<string, bool>
     */
    public function cellClasses(): array
    {
        return [
            'num' => $this->isNumeric(),
            'wide' => $this->wide,
            'mono' => $this->mono,
        ];
    }
}
