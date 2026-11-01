{{--
    The user list (T028).

    A grid like every other user-facing result set, so it inherits header
    filters, CSV/XLSX extract, the column chooser and the visible procedure
    name without this page knowing about any of them.
--}}
<x-app-shell title="Users and access">
    <x-page-head eyebrow="Setup · People and assets" title="Users and access">
        <x-slot:actions>
            @can('setup.roles.view')<a class="btn" href="{{ route('app.setup.roles.index') }}">Roles</a>@endcan
        </x-slot:actions>
        <x-slot:info>
            <p>Everyone who can sign in.</p>
            <p>Head office or branch follows from the sites a person is granted: exactly one site makes them a
               branch user at it, more than one makes them head office.</p>
        </x-slot:info>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @if ($errors->any())
        <x-notice tone="warn" title="Batch not saved">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-notice>
    @endif

    @can('setup.users.edit')
        <p class="field-help">Select users to update them together. Select all applies to this page only; changing pages or filters clears the selection.</p>
    @endcan
    <x-data-grid :grid="$grid" surface>
        @can('setup.users.edit')
            <x-slot:bulk>
                <button type="button" class="btn-primary sm" data-modal-open="users-bulk-roles">Add roles…</button>
                <button type="button" class="btn sm" data-modal-open="users-bulk-status">Change account status…</button>
            </x-slot:bulk>
        @endcan
    </x-data-grid>

    @can('setup.users.edit')
        <x-modal id="users-bulk-roles" title="Add roles to selected users">
            @if ($allRoles->isEmpty())
                <x-empty-state text="No roles have been created yet. Create a role before assigning it to users." />
                @can('setup.roles.create')
                    <a class="btn-primary" href="{{ route('app.setup.roles.create') }}">Create a role</a>
                @endcan
            @else
            <form method="POST" action="{{ route('app.setup.users.bulk.update') }}" data-bulk-form="app.setup.users"
                  data-confirm="Add these roles to the selected users?" data-confirm-action="Add roles"
                  data-confirm-text="Existing roles, direct grants and site access are kept.">
                @csrf
                <input type="hidden" name="action" value="add_roles">
                <div data-bulk-items></div>
                <p><strong><span data-bulk-count>0</span> users selected</strong> on this page.</p>
                <div class="field field-wide">
                    <label for="bulk-user-roles">Roles to add</label>
                    <select id="bulk-user-roles" name="roles[]" multiple data-select required data-placeholder="Choose roles">
                        @foreach ($allRoles as $role)<option value="{{ $role->Id }}">{{ $role->Name }}</option>@endforeach
                    </select>
                    <p class="field-help">Adds these roles to every selected user. Existing roles, direct grants and sites are kept.</p>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary" disabled>Add roles</button></div>
            </form>
            @endif
        </x-modal>
        <x-modal id="users-bulk-status" title="Change selected accounts">
            <form method="POST" action="{{ route('app.setup.users.bulk.update') }}" data-bulk-form="app.setup.users"
                  data-confirm="Change the selected accounts?" data-confirm-action="Apply change">
                @csrf
                <div data-bulk-items></div>
                <p><strong><span data-bulk-count>0</span> users selected</strong> on this page.</p>
                <x-field name="action" label="Account action" :choices="[
                    'activate' => 'Activate accounts',
                    'deactivate' => 'Deactivate accounts',
                    'unlock' => 'Unlock accounts',
                ]" value="activate" />
                <p class="field-help">Activate enables an account; unlock removes its lock. Both are needed to sign in. Deactivation blocks sign-in and keeps the audit history.</p>
                <p class="field-help">You cannot deactivate yourself. If any selected account cannot be changed, the whole batch stays unchanged.</p>
                <div class="form-actions"><button type="submit" class="btn-primary" disabled>Apply to selected users</button></div>
            </form>
        </x-modal>
    @endcan
</x-app-shell>
