{{--
    The grid's scope: search, sites, dates, page size.

    A plain GET form, so the scope ends up in the query string and a link
    pasted into a message opens what the sender was looking at (feature-rules,
    proposed §C). It also means the grid narrows without JavaScript, which is
    the difference between a slow connection being slow and being broken.

    Everything in the query string that is NOT this grid's own travels through
    as a hidden input. Two grids share a page (that is what the GridKey's `:bags`
    qualification is for), and submitting one must not reset the other's page or
    drop the other's search — a bug that only ever appears on the screens that
    matter most, the ones with a summary above a detail.

    Props: $grid, $branches, $formId
--}}
@php
    $definition = $grid->definition;
    $p = fn (string $name) => $grid->param($name);
    $mine = array_map($p, ['q', 'branches', 'from', 'to', 'sort', 'dir', 'page', 'size', 'f']);
@endphp

<form class="dg-scope" id="{{ $formId }}" method="GET" action="{{ $grid->baseUrl }}">
    @foreach ($grid->query as $name => $value)
        @continue(in_array($name, $mine, true) || is_array($value))
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    {{-- The sort survives a search: narrowing the rows is not a request to
         re-order them. --}}
    @if ($grid->sort() !== null)
        <input type="hidden" name="{{ $p('sort') }}" value="{{ $grid->sort() }}">
        <input type="hidden" name="{{ $p('dir') }}" value="{{ $grid->ascending() ? 'asc' : 'desc' }}">
    @endif

    <div class="field-row">
        @if ($definition->searchable())
            <x-field :name="$p('q')" label="Search" :value="$grid->gridQuery->search"
                     placeholder="Site, reference, name…"
                     help="One filter across the columns worth searching. The source applies it, not the browser." />
        @endif

        @if ($definition->dateRange())
            <x-field :name="$p('from')" label="From" type="date" :value="$grid->gridQuery->from"
                     help="Leave empty and the procedure applies its own window." />
            <x-field :name="$p('to')" label="To" type="date" :value="$grid->gridQuery->to" />
        @endif

        {{-- Rule 3.3: the branches selector belongs to head office. In the
             branch workspace BranchContext has already pinned the site, so the
             CONTROLLER passes no branches and the selector does not appear —
             and GridService ignores whatever arrives in the query string
             regardless, because a scope the URL could widen is not a scope. --}}
        @if ($definition->branchSelector() && $branches !== null && count($branches) > 0)
            <div class="field field-wide">
                <label for="{{ $formId }}-branches">Sites</label>
                <select id="{{ $formId }}-branches" name="{{ $p('branches') }}[]" multiple data-select
                        data-placeholder="Every site you are granted">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->BranchId }}"
                            @selected(in_array((int) $branch->BranchId, $grid->gridQuery->branchIds, true))>{{ $branch->Name }}</option>
                    @endforeach
                </select>
                <p class="field-help">None selected means every site you are granted.</p>
            </div>
        @endif
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Run</button>
        <a class="btn-ghost" href="{{ $grid->baseUrl }}">Reset</a>
    </div>
</form>
