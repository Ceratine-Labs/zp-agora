@props(['columns' => null])

{{--
    A block of labelled facts about one record — the body of every detail
    screen.

    NOT <x-kpi-strip>. A KPI is a headline about the business and is sized to
    be read from across a room; a fact is a property of the record you are
    already looking at, and there are usually six to a dozen of them. Rendering
    facts as KPI tiles makes a detail page look like a dashboard and pushes
    everything that matters below the fold.

    NOT <x-table> either. A two-column table of label and value cannot carry
    the third thing that makes a detail page worth reading: the line saying
    what the value MEANS. "Item number 1" is not an answer; "unique within this
    site only — the same number is a different product at the other twenty-one"
    is. <x-fact> takes that as its slot and it is the reason this exists.

    Built 20 September 2026 for the stock master detail screen (T025), after
    that screen was first written with the report template's `.grid-cards` and
    rendered as a wall of unstyled text — classes no Agora stylesheet has ever
    defined. `columns` fixes the count where a screen wants a specific shape;
    left alone it fits as many as the width allows and collapses to one on a
    phone.
--}}
<div
    {{ $attributes->merge(['class' => 'facts']) }}
    @if ($columns) style="--facts-columns: {{ (int) $columns }}" @endif
>{{ $slot }}</div>
