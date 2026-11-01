<x-app-shell :title="$role->exists ? 'Edit '.$role->Name : 'Create role'">
    <x-page-head eyebrow="Setup · Roles" :title="$role->exists ? 'Edit '.$role->Name : 'Create role'">
        <x-slot:info>
            <p>Roles add permissions and menu entries to each assigned user's direct grants. Changing a role changes access for everyone assigned to it.</p>
            <p>Site access and landing pages stay on the person. No menu ticks on either the person or their roles means the whole menu, subject to permissions.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ route('app.setup.roles.index') }}">Back to roles</a></x-slot:actions>
    </x-page-head>
    @if ($errors->any())
        <x-notice tone="warn" title="Role not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif
    <form method="POST" action="{{ $role->exists ? route('app.setup.roles.update', ['role' => $role->Id]) : route('app.setup.roles.store') }}">
        @csrf
        @if ($role->exists) @method('PUT') @endif
        <input type="hidden" name="role_form" value="1">
        <x-card title="Role details">
            <div class="field-row">
                <x-field name="Name" label="Role name" :value="old('Name', $role->Name)" required />
                <x-field name="Code" label="Code" :value="old('Code', $role->Code)" info="A unique code using letters, numbers, hyphens or underscores." required />
            </div>
        </x-card>
        <x-card title="Permissions" collapsible open>
            @foreach ($permissionGroups as $module => $permissions)
                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <div class="role-list">
                    @foreach ($permissions as $permission)
                        <label class="role-row">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->Id }}" @checked(in_array((int) $permission->Id, array_map('intval', old('role_form') ? old('permissions', []) : $permissionIds), true))>
                            <span class="role-name"><code>{{ $permission->Code }}</code></span>
                            <span class="role-primary">{{ $permission->Name }}</span>
                        </label>
                    @endforeach
                </div>
            @endforeach
        </x-card>
        <x-card title="Menu entries" collapsible>
            <p class="field-help">These entries combine with each user's direct menu ticks and other roles. Entries still require their own permissions.</p>
            @foreach ($workspaces as $workspace)
                <h4 class="perm-module">{{ $workspace['label'] }}</h4>
                <div class="menu-ticks">
                    @foreach ($workspace['rows'] as $row)
                        <label class="role-row menu-tick" style="--depth: {{ $row['depth'] }}">
                            <input type="checkbox" name="menu[]" value="{{ $row['item']->Id }}" @checked(in_array((int) $row['item']->Id, array_map('intval', old('role_form') ? old('menu', []) : $menuIds), true))>
                            <span class="role-name">{{ $row['section']->Label }} · {{ $row['item']->Label }}</span>
                        </label>
                    @endforeach
                </div>
            @endforeach
        </x-card>
        <div class="form-actions"><button class="btn-primary" type="submit">Save role</button></div>
    </form>
</x-app-shell>
