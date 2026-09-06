{{--
    The role matrix (T028).

    Every role down the side, every permission across, grouped by module. Read
    only for now: changing a grant is a seeder change today, because the grants
    are the system's own definition of the six roles rather than customer data,
    and a screen that edits them needs an audit trail this does not have yet.
--}}
<x-app-shell title="Roles and permissions">
    <x-page-head
        eyebrow="Setup · Users and access"
        title="Roles and permissions"
        blurb="What each of the six roles may do. Slugs are module.resource.action — the resource is the middle part, so edit, save and delete each sit behind their own permission.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.users.index') }}">Back to users</a>
        </x-slot:actions>
    </x-page-head>

    <x-notice tone="info" title="Read only" collapsible>
        These grants are seeded, not edited here. They are the system's own definition of the six roles
        rather than the customer's data, so they change in <code>RolePermissionSeeder</code> and arrive
        with a deploy. A screen that edited them would need an audit trail first.
    </x-notice>

    @foreach ($permissions as $module => $rows)
        <x-card :title="ucfirst($module)" flush>
            <div class="table-scroll">
                <table class="dt matrix">
                    <thead>
                        <tr>
                            <th class="matrix-perm">Permission</th>
                            @foreach ($roles as $role)
                                <th class="matrix-role">{{ $role->Name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $permission)
                            <tr>
                                <td class="matrix-perm">
                                    <code>{{ $permission->Code }}</code>
                                    <span class="matrix-hint">{{ $permission->Name }}</span>
                                </td>
                                @foreach ($roles as $role)
                                    @php($has = ($granted[$role->Id] ?? collect())->has($permission->Id))
                                    <td class="matrix-cell {{ $has ? 'yes' : 'no' }}">
                                        <span aria-label="{{ $has ? 'granted' : 'not granted' }}">{{ $has ? '✓' : '·' }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach
</x-app-shell>
