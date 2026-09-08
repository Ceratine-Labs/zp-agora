{{--
    The grid on a phone: a card per row, three columns on the face, the rest
    behind an expand (plan §3.9, "clean over capable").

    Rendered alongside the table rather than instead of it, and the stylesheet
    shows one or the other. Two reasons, and the second is the one that decided
    it: server-side device detection is T016 and does not exist yet, so a
    server-rendered choice would have to guess; and a viewport is a spectrum —
    a narrow desktop window is the same problem as a phone and a media query
    answers both without a preference to set.

    The cost is duplicated markup for the rows on the page. That is fifty rows
    of three fields, not fifty rows of twenty, and it is bounded by the page
    size rather than by the answer.

    Three columns because that is what fits at 375px without the card becoming
    a table with rounded corners. WHICH three is the user's: the chooser sets
    the order and the visibility, and the cards take the first three of what
    survives.

    A linked column and the row's actions are rendered here too, from the same
    GridDefinition hooks the table uses. A phone is where "how do I open this
    person" is hardest to answer, so it is the last place to leave them out.

    Props: $grid, $slug
--}}
@php
    $face = $grid->cardColumns();
    $rest = $grid->cardRest();
    $definition = $grid->definition;
@endphp

<ul class="dg-cards" data-cards>
    @foreach ($grid->rows as $index => $row)
        @php($rowUrl = $definition->rowUrl($row))
        @php($rowActions = $definition->rowActions($row))
        <li class="dg-card">
            <div class="dg-card-face">
                @foreach ($face as $view)
                    @php($column = $view->column)
                    <div @class(['dg-card-field', 'is-lead' => $loop->first, 'num' => $column->isNumeric()])>
                        <span class="dg-card-label">{{ $column->label }}</span>
                        <span class="dg-card-value">
                            @if ($column->link && $rowUrl)
                                <a class="dg-row-link" href="{{ $rowUrl }}">@include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])</a>
                            @else
                                @include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>

            @if ($rest !== [])
                <details class="dg-card-more">
                    <summary>{{ count($rest) }} more</summary>
                    <dl>
                        @foreach ($rest as $view)
                            @php($column = $view->column)
                            <dt>{{ $column->label }}</dt>
                            <dd @class(['num' => $column->isNumeric()])>
                                @include($definition->cellsPartial(), ['row' => $row, 'column' => $column, 'grid' => $grid])
                            </dd>
                        @endforeach
                    </dl>
                </details>
            @endif

            {{-- Below the expand, not above it: the actions are what you do
                 AFTER reading the row, and on a phone a row of buttons between
                 the values and "3 more" pushes the values off the top. --}}
            @if ($rowActions !== [])
                <div class="dg-card-actions">
                    @foreach ($rowActions as $action)
                        <a class="{{ ($action['primary'] ?? false) ? 'btn-primary sm' : 'btn sm' }}"
                           href="{{ $action['url'] }}"
                           @foreach (($action['attributes'] ?? []) as $name => $value) {{ $name }}="{{ $value }}" @endforeach
                        >{{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </li>
    @endforeach
</ul>
