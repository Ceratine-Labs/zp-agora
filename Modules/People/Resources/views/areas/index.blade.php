<x-app-shell title="Employee areas">
    <x-page-head eyebrow="Setup · People and assets" title="Employee areas" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>PumpIT's employee areas (BRN_EmployeeArea): the counting areas a person may be signed on to when a stock count is captured. PumpIT's own sign-on screen offers only the active people of an area.</p>
            <p>An inactive person's rows are kept, and a code on no employee is marked "Not on the employee list" rather than left blank.</p>
        </x-slot:info>
        <x-slot:actions>
            @can('people.employees.view')<a class="btn" href="{{ route('app.setup.employees.index') }}">Employees</a>@endcan
        </x-slot:actions>
    </x-page-head>
    <x-data-grid :grid="$grid" :branches="$branches" surface>
        <x-slot:scope>
            <div class="field">
                <label for="areas-status">Employee status</label>
                <select id="areas-status" name="status">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot:scope>
    </x-data-grid>
</x-app-shell>
