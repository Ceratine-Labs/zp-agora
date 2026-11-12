@php($f = $grid->param('f'))
<x-app-shell title="Employees">
    <x-page-head eyebrow="Setup · People and assets" title="Employees" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>The people on PumpIT's employee list (BRN_Employee), by site. PumpIT keeps trading on these rows — every till sign-on, cashup and stock count names one of these codes — so Agora reads them and does not change them.</p>
            <p>The name is composed from PumpIT's surname and first name, as PumpIT writes it. An inactive person stays on every cashup and count they worked; open one to see those shifts. Posts are numbered per site, so pick a post by name.</p>
        </x-slot:info>
        <x-slot:actions>
            @can('people.posts.view')<a class="btn" href="{{ route('app.setup.employee-posts.index') }}">Employee posts</a>@endcan
            @can('people.areas.view')<a class="btn" href="{{ route('app.setup.employee-areas.index') }}">Employee areas</a>@endcan
        </x-slot:actions>
    </x-page-head>
    <x-data-grid :grid="$grid" :branches="$branches" surface>
        <x-slot:scope>
            <div class="field">
                <label for="employees-status">Status</label>
                <select id="employees-status" name="status">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="employees-post">Post</label>
                <input type="hidden" name="{{ $f }}[Post][eq]" value="1">
                <select id="employees-post" name="{{ $f }}[Post][q]" data-select data-placeholder="All posts">
                    <option value="">All posts</option>
                    @if ($post !== '' && ! in_array($post, $posts, true))
                        <option value="{{ $post }}" selected>{{ $post }}</option>
                    @endif
                    @foreach ($posts as $name)
                        <option value="{{ $name }}" @selected($post === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot:scope>
    </x-data-grid>
</x-app-shell>
