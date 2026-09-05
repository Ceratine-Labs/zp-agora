{{--
    The grid gallery: /dev/grids.

    Two grids on one page, because that is the case the GridKey's qualification
    exists for — `app.dev.grids:dayclose` and `app.dev.grids:branches` — and the
    only way to see that sorting one does not page the other.

    One is over a stored procedure, one is over Eloquent, and they are rendered
    by the same component with the same props. That is the claim this page is
    here to let somebody check by looking.
--}}
<x-app-shell title="Data grid">
    <x-page-head eyebrow="Development" title="Data grid"
                 blurb="Every user-facing result set in Agora is this component. Two grids below: one over a stored procedure, one over a query builder, rendered identically. Sort a column, filter a header, hide a column, drag a border, extract — and reload, because the layout is yours and it persists.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('dev.theme') }}">Theme</a>
        </x-slot:actions>
    </x-page-head>

    <x-notice tone="info" title="What is on this page" collapsible style="margin-bottom:16px">
        <p>The first grid runs <code>agora.usp_Reports_GridDayClose</code> — the same procedure the Reports
           module renders through <code>&lt;x-table&gt;</code>. The procedure filters, sorts, pages and counts;
           PHP sends eight parameters and receives one page.</p>
        <p>The second runs a query builder over <code>agora.Branch</code>. It carries the typed header filters,
           because an Eloquent source can answer them — a procedure needs a <code>@FiltersJson</code> parameter
           before it may declare any, and the day-close procedure does not have one.</p>
        <p>Column order, widths, hidden columns and text size are saved to <code>agora.UserGridColumn</code>
           against your user and this grid's key, so they follow you to the next device.</p>
    </x-notice>

    @foreach ($grids as $grid)
        <x-card :title="$grid->definition->title()" :sub="$grid->definition->blurb()" flush>
            <x-data-grid :grid="$grid" :branches="$branches" />
        </x-card>
    @endforeach
</x-app-shell>
