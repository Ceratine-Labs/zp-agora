{{--
    The header filter row (feature-rules §3.1).

    Each column carries its own control and the control matches what the cell
    renders: text gets contains-or-exact, a number or a date gets the six
    comparators, and a column with a declared value set gets the Excel-style
    tick list. The type is derived from the column's FORMAT, so a money column
    cannot end up with a substring filter by omission.

    The inputs live inside the table but belong to the scope form above it,
    through `form="…"`. HTML will not let a <form> wrap a <tr>, and the
    alternative — a JS submit — would mean the filters stopped working the
    moment a script failed to load. This way the whole grid still narrows with
    no JavaScript at all.

    Nothing here filters anything. The values go into the query string, the
    source applies them, and the export re-runs the same source with the same
    values. One set of matching semantics, in the engine that owns the data —
    which is the trap feature-rules §3.2 says ZP paid for and we should design
    out.

    Props: $grid, $columns (GridColumnView[]), $formId, $selectable
--}}
@php
    $definition = $grid->definition;
    $specs = $definition->filters();
    $submitted = $grid->query[$grid->param('f')] ?? [];
    $submitted = is_array($submitted) ? $submitted : [];
@endphp

<tr class="dg-filters">
    @if ($selectable)<th class="pick"></th>@endif

    @foreach ($columns as $view)
        @php
            $column = $view->column;
            $spec = $specs[$column->key] ?? null;
            $name = $grid->param('f').'['.$column->key.']';
            $current = is_array($submitted[$column->key] ?? null) ? $submitted[$column->key] : [];
            $value = is_scalar($current['q'] ?? null) ? (string) $current['q'] : '';
            $operator = is_scalar($current['op'] ?? null) ? (string) $current['op'] : '';
            $chosen = is_array($current['in'] ?? null) ? array_map('strval', $current['in']) : [];
        @endphp

        <th @class(['num' => $column->isNumeric()])>
            @if ($spec === null)
                {{-- Deliberately empty: this column declared no filter, and an
                     empty cell says so more clearly than a disabled box. --}}
            @elseif ($spec->type === 'set' && $spec->options !== [])
                {{-- A tick list, capped at 500 values by GridFilter — above
                     that a filter has become a query of its own. --}}
                <select name="{{ $name }}[in][]" form="{{ $formId }}" multiple size="1"
                        data-select data-placeholder="Any"
                        aria-label="Filter {{ $column->label }}">
                    @foreach ($spec->options as $option)
                        <option value="{{ $option }}" @selected(in_array((string) $option, $chosen, true))>{{ $option }}</option>
                    @endforeach
                </select>
            @else
                <div class="dg-filter">
                    @if ($spec->type !== 'text')
                        <select name="{{ $name }}[op]" form="{{ $formId }}"
                                aria-label="How to compare {{ $column->label }}">
                            @foreach (['eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤'] as $key => $glyph)
                                <option value="{{ $key }}" @selected($operator === $key)>{{ $glyph }}</option>
                            @endforeach
                        </select>
                    @endif

                    <input type="{{ $spec->type === 'date' ? 'date' : ($spec->type === 'number' ? 'number' : 'text') }}"
                           name="{{ $name }}[q]"
                           form="{{ $formId }}"
                           value="{{ $value }}"
                           step="any"
                           placeholder="{{ $spec->type === 'text' ? 'contains…' : '' }}"
                           aria-label="Filter {{ $column->label }}">
                </div>
            @endif
        </th>
    @endforeach
</tr>
