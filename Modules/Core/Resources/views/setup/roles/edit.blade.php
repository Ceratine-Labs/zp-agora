<x-app-shell :title="$role->exists ? 'Edit '.$role->Name : 'Create role'">
    <x-page-head eyebrow="Setup · Roles" :title="$role->exists ? 'Edit '.$role->Name : 'Create role'">
        <x-slot:info>
            <p>Roles add permissions to each assigned user's direct grants. Changing a role changes access for everyone assigned to it.</p>
            <p>Site access and landing pages stay on the person. Menus follow permissions.</p>
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
                <div class="role-list" data-role-permission-group>
                    <div class="menu-ticks-bulk">
                        <button type="button" class="btn sm" data-select-permissions>Select all</button>
                        <button type="button" class="btn sm" data-clear-permissions>Deselect all</button>
                    </div>
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
        <div class="form-actions"><button class="btn-primary" type="submit">Save role</button></div>
    </form>
    <script>
        document.querySelectorAll('[data-role-permission-group]').forEach(function (group) {
            function setPermissions(checked) {
                group.querySelectorAll('input[name="permissions[]"]').forEach(function (box) {
                    box.checked = checked;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
            group.querySelector('[data-select-permissions]').addEventListener('click', function () { setPermissions(true); });
            group.querySelector('[data-clear-permissions]').addEventListener('click', function () { setPermissions(false); });
        });
    </script>
</x-app-shell>
