<?php

namespace App\Grid;

/**
 * One header filter, and the normalising of what the user typed into it.
 *
 * ZP's two filter shapes are carried over unchanged (feature-rules §3.1),
 * because they are the two questions a person actually asks of a column:
 *
 *   {q, eq}      a comparator, a substring or an exact match
 *   {in: [...]}  the Excel-style tick list, capped at MAX_SET values
 *
 * The cap is not decoration. An uncapped `in` list is a query of its own
 * arriving in a query string: a thousand values is a thousand-element IN
 * clause the optimiser will not fold, and it is also a URL nothing can log.
 *
 * A filter is a SPEC here, not a value. The values live on GridQuery, which is
 * what a source is handed. This class knows what a column will accept and how
 * to clean what arrived; it never decides what matches — that is the
 * procedure's job for a procedure source and the query builder's for an
 * Eloquent one, and never both (feature-rules §3.2, "the trap ZP paid for").
 */
final class GridFilter
{
    /** The comparators a numeric or date column offers. */
    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'];

    /** How many values a tick-list may carry before it is a query rather than a filter. */
    public const MAX_SET = 500;

    /**
     * @param  string  $column  the column key this filters
     * @param  string  $type  text|number|date|set
     * @param  array<int, string>  $options  the tick list, for a set filter
     */
    public function __construct(
        public string $column,
        public string $type,
        public array $options = [],
    ) {
        if (! in_array($type, ['text', 'number', 'date', 'set'], true)) {
            throw new \InvalidArgumentException("Unknown filter type [{$type}] on [{$column}].");
        }

        $this->options = array_slice(array_values(array_unique($options)), 0, self::MAX_SET);
    }

    /** The spec a column carries when nothing more specific has been declared. */
    public static function forColumn(GridColumn $column): self
    {
        return new self($column->key, $column->filterType(), $column->options);
    }

    /**
     * Clean one filter's submitted value into the shape a source is given, or
     * null when nothing usable arrived.
     *
     * Everything that is not recognised is dropped rather than passed on. A
     * filter the source does not understand is worse than no filter: the user
     * sees a narrowed set of rows and no reason for it.
     *
     * @param  mixed  $input  whatever arrived in the query string for this column
     * @return array{column: string, type: string, op?: string, value?: string, in?: array<int, string>}|null
     */
    public function normalise(mixed $input): ?array
    {
        if (! is_array($input)) {
            $input = ['q' => $input];
        }

        if ($this->type === 'set') {
            /** @var array<int, mixed> $values */
            $values = is_array($input['in'] ?? null) ? $input['in'] : [];

            $values = collect($values)
                ->reject(fn (mixed $v) => is_array($v) || is_object($v))
                ->map(fn (mixed $v) => trim((string) $v))
                ->filter(fn (string $v) => $v !== '')
                ->unique()
                ->take(self::MAX_SET)
                ->values()
                ->all();

            return $values === [] ? null : ['column' => $this->column, 'type' => 'set', 'in' => $values];
        }

        $value = $input['q'] ?? null;

        if (is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        // `eq` is the shape ZP used for "exact match" — a checkbox next to the
        // box, so it arrives as "1". Anything else is a comparator by name. An
        // operator this column does not offer falls back to the default rather
        // than being sent on; see the note above about silent narrowing.
        $operator = (string) ($input['eq'] ?? $input['op'] ?? '');
        $operator = $operator === '1'
            ? 'eq'
            : (in_array($operator, $this->operators(), true) ? $operator : $this->defaultOperator());

        return ['column' => $this->column, 'type' => $this->type, 'op' => $operator, 'value' => $value];
    }

    /**
     * The comparators this column offers.
     *
     * Text gets `contains` as well, and it is the default — asking a person to
     * choose "contains" before typing a site name is a control in the way of
     * the thing it is for.
     *
     * @return array<int, string>
     */
    public function operators(): array
    {
        return $this->type === 'text'
            ? array_merge(['contains'], self::OPERATORS)
            : self::OPERATORS;
    }

    /** Text contains by default; a number or a date compares by equality. */
    private function defaultOperator(): string
    {
        return $this->type === 'text' ? 'contains' : 'eq';
    }
}
