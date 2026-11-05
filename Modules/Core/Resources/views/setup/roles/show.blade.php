<x-app-shell :title="$role->Name">
    <x-page-head eyebrow="Setup · Roles" :title="$role->Name" :blurb="$role->Code">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.roles.index') }}">Back to roles</a>
            @can('setup.roles.edit')<a class="btn-primary" href="{{ route('app.setup.roles.edit', ['role' => $role->Id]) }}">Edit role</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('role')<x-notice tone="warn" title="Role not deleted">{{ $message }}</x-notice>@enderror
    <x-card title="Assigned users">
        @if ($members->isEmpty())<x-empty-state text="Nobody is assigned to this role yet. Assign it from a user's edit page." />@endif
        <ul class="perm-list">
            @foreach ($members as $member)
                <li>@can('setup.users.view')<a href="{{ route('app.setup.users.show', ['user' => $member->Id]) }}">{{ $member->UserName }}</a>@else{{ $member->UserName }}@endcan</li>
            @endforeach
        </ul>
    </x-card>
    <x-card title="Permissions" collapsible open>
        @if ($permissionIds === [])<x-empty-state text="This role grants no permissions." />@endif
        @foreach ($permissionGroups as $module => $permissions)
            @if ($permissions->whereIn('Id', $permissionIds)->isNotEmpty())
                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <ul class="perm-list">@foreach ($permissions->whereIn('Id', $permissionIds) as $permission)<li><code>{{ $permission->Code }}</code> · {{ $permission->Name }}</li>@endforeach</ul>
            @endif
        @endforeach
    </x-card>
    @can('setup.roles.delete')
        <form method="POST" action="{{ route('app.setup.roles.destroy', ['role' => $role->Id]) }}" data-confirm="Delete this role?" data-confirm-action="Delete role" data-confirm-danger>
            @csrf @method('DELETE')
            <button class="btn" type="submit" @disabled($members->isNotEmpty())>Delete role</button>
            @if ($members->isNotEmpty())<p class="field-help">Remove all user assignments before deleting this role.</p>@endif
        </form>
    @endcan
</x-app-shell>
