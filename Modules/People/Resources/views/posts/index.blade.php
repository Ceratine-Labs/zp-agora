<x-app-shell title="Employee posts">
    <x-page-head eyebrow="Setup · People and assets" title="Employee posts" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>PumpIT's posts (BRN_EmployeePost). Each site numbers its own, so "Site Manager" is post 46 at one site and 48 at another; a post is always the site and the number together. A post's name opens the people who hold it at that site.</p>
            <p>Stock control marks the posts whose holders carry stock losses on the deduction schedule.</p>
        </x-slot:info>
        <x-slot:actions>
            @can('people.employees.view')<a class="btn" href="{{ route('app.setup.employees.index') }}">Employees</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Post not added">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Post not added"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif
    @if ($branch !== null && $nextPost !== null)
        <x-notice tone="info">The next post at {{ $branch->Name }} would be number {{ $nextPost }} (agora.usp_People_NextPost — PumpIT's sp_GetNextEmployeePost, rule for rule).</x-notice>
        @if ($writable)
            @can('people.posts.save')
                <form method="POST" action="{{ route('app.setup.employee-posts.store') }}"
                      data-confirm="Write this to PumpIT?" data-confirm-action="Add post"
                      data-confirm-text="A post is inserted into PumpIT's BRN_EmployeePost at {{ $branch->Name }}, as number {{ $nextPost }} unless another is added first."
                      data-diff="PumpIT BRN_EmployeePost · {{ $branch->Name }} · post {{ $nextPost }}" data-diff-note="It takes the site's next post number and is recorded with your reason. A post is not taken away again from Agora.">
                    @csrf
                    <input type="hidden" name="BranchId" value="{{ $branch->BranchId }}">
                    <x-card :title="'Add a post at '.$branch->Name" sub="Written to PumpIT's BRN_EmployeePost">
                        <div class="field-row">
                            <x-field name="PostDescription" label="Post" :value="old('PostDescription')" help="Up to 50 characters, unique at this site."
                                     data-diff-field="PostDescription" data-diff-old="" />
                            <x-field name="StockControl" type="bool" label="Stock control" placeholder="Holders carry stock losses" :value="(bool) old('StockControl', false)"
                                     data-diff-field="StockControl" data-diff-old="0" />
                        </div>
                        <x-field name="Reason" label="Reason" :value="old('Reason')" help="At least 10 characters." />
                        <div class="form-actions"><button class="btn-primary" type="submit">Add post</button></div>
                    </x-card>
                </form>
            @endcan
        @endif
    @endif
    <x-data-grid :grid="$grid" :branches="$branches" surface />
    @include('people::partials.confirm-diff')
</x-app-shell>
