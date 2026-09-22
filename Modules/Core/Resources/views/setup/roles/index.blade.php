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

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @error('menu')
        <x-notice tone="warn" title="Menu access not saved">{{ $message }}</x-notice>
    @enderror

    {{-- The menu leads, because it is the half an administrator can actually
         change and the half the customer asked for. --}}
    <x-card id="menu" title="Menu access" flush
            sub="Which entries of the menu each role sees — and therefore may open." collapsible open>
        <form method="POST" action="{{ route('app.setup.roles.menu.update') }}">
            @csrf
            @method('PUT')

            <div class="card-pad">
                <x-notice tone="info" title="Ticking nothing ticks everything">
                    A role ticked for nothing sees the whole menu — nobody starts out restricted, and
                    reading an empty column the other way would blank the navigation for everyone at once.
                    <strong>Tick one entry in a column and the ticks become the whole of what that role
                    sees.</strong> A tick is access, not decoration: an entry nobody's roles tick is not
                    reachable by typing its address either. One person's exception belongs on
                    <a href="{{ route('app.setup.users.index') }}">their own screen</a>, not here.
                </x-notice>
            </div>

            @foreach ($menuWorkspaces as $code => $workspace)
                <div class="table-scroll">
                    <table class="dt matrix menu-matrix">
                        <thead>
                            <tr>
                                <th class="matrix-perm">{{ $workspace['label'] }}</th>
                                @foreach ($roles as $role)
                                    <th class="matrix-role">{{ $role->Name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @php($section = null)
                            @foreach ($workspace['rows'] as $row)
                                @if ($section !== $row['section']->Code)
                                    @php($section = $row['section']->Code)
                                    <tr class="menu-matrix-section">
                                        <td colspan="{{ $roles->count() + 1 }}">{{ $row['section']->Label }}</td>
                                    </tr>
                                @endif

                                @php($id = (int) $row['item']->Id)
                                <tr>
                                    <td class="matrix-perm">
                                        <span class="menu-matrix-label" style="--depth: {{ $row['depth'] }}">{{ $row['item']->Label }}</span>
                                        <span class="matrix-hint">
                                            {{ $row['item']->Path }}@if ($row['item']->PermissionCode) · {{ $row['item']->PermissionCode }}@endif
                                        </span>
                                    </td>
                                    @foreach ($roles as $role)
                                        <td class="matrix-cell">
                                            <label class="menu-matrix-tick">
                                                <input type="checkbox" name="menu[{{ $role->Id }}][]" value="{{ $id }}"
                                                       @checked(isset($menuGranted[(int) $role->Id][$id]))>
                                                <span class="sr-only">{{ $role->Name }} · {{ $row['item']->Label }}</span>
                                            </label>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <div class="card-pad form-actions">
                <button type="submit" class="btn-primary">Save menu access</button>
            </div>
        </form>
    </x-card>

    <x-notice tone="info" title="The permissions below are read only" collapsible>
        These grants are seeded, not edited here. They are the system's own definition of the six roles
        rather than the customer's data, so they change in <code>RolePermissionSeeder</code> and arrive
        with a deploy. A screen that edited them would need an audit trail first. The menu above is the
        customer's own structure, which is why that half is theirs to set.
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
