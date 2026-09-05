{{--
    The procedure behind a screen, shown — the mockup's `sqlbox`.

    feature-rules §3.4 says a grid names the procedure it came from, and rule 2
    says the customer is meant to be able to open that procedure and change it.
    This is the strong form of both: the name, and optionally the body, on the
    screen that ran it.

    Two things it deliberately does not do:

      * **It does not read the database.** The body is a prop. A component that
        went and fetched `sys.sql_modules` would be a component with a query in
        it, and on the customer's instance that query would run against a
        249 GB production server on every page load.
      * **It does not offer to edit.** This is a window, not an editor. Changes
        go through a migration, or through the customer in SSMS.

    Collapsed by default: on a screen that has an answer, the SQL behind it is
    provenance rather than the point.
--}}
@props([
    'procedure' => null,
    'title' => 'Report SQL — read only',
    'open' => false,
])

<details {{ $attributes->merge(['class' => 'sqlwrap']) }} @if ($open) open @endif>
    <summary>
        <span class="eyebrow">{{ $title }}</span>
        @if ($procedure)<span class="mono sqlwrap-proc">{{ $procedure }}</span>@endif
    </summary>
    <pre class="sqlbox"><code>{{ trim($slot) !== '' ? $slot : '-- SQL not published for this screen' }}</code></pre>
</details>
