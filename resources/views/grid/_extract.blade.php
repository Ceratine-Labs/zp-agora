{{--
    The extract drawer: CSV, XLSX and the copy box.

    feature-rules §3.2 lives here — the file is what the person is LOOKING at,
    produced by re-running the same source with the page opened up, so the
    numbers cannot disagree with the ones on screen.

    Lifted out of <x-data-grid> on 7 September 2026 so the recon run screen can
    have the same three buttons. That screen renders a bespoke table — it
    carries tick boxes, an execute form and a row-expand panel that the grid
    shell has no vocabulary for — but "how do I get this into Excel" is not a
    question whose answer should depend on which of those two a screen happens
    to use. One drawer, two callers, and a third that reimplemented it would be
    the thing this file exists to prevent.

    Props: $grid (App\Grid\GridResult), $columns (GridColumnView[]),
           $definition, $slug
--}}
    <x-drawer :id="'dg-'.$slug.'-extract'" title="Extract" copy
              note="The file is what you are looking at — your filters, your sort, your visible columns, in your order. It is produced by re-running the same source with the page opened up, so the numbers cannot disagree with the ones above.">

        @if ($grid->overCeiling())
            <x-notice tone="stop" title="Too big for a download">
                <p>This answer is {{ \App\Support\Format::n($grid->total) }} rows and the grid extracts up to
                   {{ \App\Support\Format::n($definition->exportCeiling()) }}. Narrow it above, or send it to the
                   export centre, which produces the same file out of the request cycle.</p>
            </x-notice>
        @else
            <dl class="dg-extract-facts">
                <dt>Rows</dt><dd>{{ \App\Support\Format::n($grid->total) }}</dd>
                <dt>Columns</dt><dd>{{ collect($columns)->map(fn ($v) => $v->column->label)->implode(', ') }}</dd>
                <dt>Sort</dt>
                <dd>{{ $grid->sort() ?? 'the source’s own order' }}@if ($grid->sort()), {{ $grid->ascending() ? 'ascending' : 'descending' }}@endif</dd>
            </dl>

            <div class="dg-extract-actions">
                <a class="btn-primary" href="{{ $grid->extractUrl('xlsx') }}" data-extract>Download .xlsx</a>
                <a class="btn-ghost" href="{{ $grid->extractUrl('csv') }}" data-extract>Download .csv</a>
            </div>

            <p class="dg-extract-note">
                Numbers come down as numbers and dates as dates, not as the formatted text on the screen —
                so the receiving column adds up and sorts without being cleaned first.
            </p>

            {{-- The copy button copies this. A textarea rather than a <pre>
                 because select-all inside one is a single keystroke, and on a
                 locked-down desktop where the download is blocked this is the
                 way the data still gets out. --}}
            <label class="dg-extract-label" for="dg-{{ $slug }}-csv">The first rows, as comma-separated text</label>
            <textarea id="dg-{{ $slug }}-csv" readonly spellcheck="false" rows="12">{{ collect($columns)->map(fn ($v) => $v->column->label)->implode(',') }}
@foreach ($grid->rows as $row){{ collect($columns)->map(function ($v) use ($row) {
    $value = $row->{$v->column->key} ?? '';
    return str_contains((string) $value, ',') ? '"'.str_replace('"', '""', (string) $value).'"' : $value;
})->implode(',') }}
@endforeach</textarea>
        @endif
    </x-drawer>
