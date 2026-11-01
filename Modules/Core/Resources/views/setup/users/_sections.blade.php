    <x-card class="user-access-section" id="details" title="Account details">
        @if ($editing)
        <form method="POST" action="{{ route('app.setup.users.details.update', ['user' => $person->Id]) }}">
            @csrf
            @method('PUT')

            <div class="field-row">
                <x-field name="UserName" label="Name" :value="old('UserName', $person->UserName)"
                         info="What the footer and every audit row calls them." />
                <x-field name="EmailAddress" label="Email address" type="email"
                         :value="old('EmailAddress', $person->EmailAddress)"
                         info="The sign-in identity, and where a set-password link goes. Required: an account without one cannot be used." />
                <x-field name="UserCode" label="Code" :value="old('UserCode', $person->UserCode)"
                         info="Their user number in PumpIT — the number people say out loud." />
            </div>

            <div class="field-row">
                {{-- Only the sites they are granted — the controller says why. --}}
                <x-field name="HomeBranchId" label="Home site"
                         :value="old('HomeBranchId', $person->HomeBranchId)"
                         :choices="$homeBranchChoices"
                         info="Set this and the person works in the branch workspace, at that site. Granting exactly one site sets it for you." />
                <x-field name="IsActive" label="Active" type="bool" :value="old('IsActive', $person->IsActive)"
                         placeholder="May sign in"
                         info="Somebody who has left is deactivated, never deleted — the rows they wrote stay attributable." />
                <x-field name="IsLocked" label="Locked" type="bool" :value="old('IsLocked', $person->IsLocked)"
                         placeholder="Locked out"
                         info="A locked account is refused at sign-in. Carried over from PumpIT." />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save details</button>
            </div>
        </form>
        @else
        <div class="field-row">
            <x-field name="UserName" label="Name" :value="$person->UserName" readonly />
            <x-field name="EmailAddress" label="Email address" :value="$person->EmailAddress" readonly />
            <x-field name="UserCode" label="Code" :value="$person->UserCode ?: '—'" readonly />
        </div>
        <div class="field-row">
            <x-field name="HomeBranchId" label="Home site" :value="$person->HomeBranchId ? ($homeBranchName ?? 'Site '.$person->HomeBranchId) : 'None — head office'" readonly />
            <x-field name="IsActive" label="Active" :value="$person->IsActive ? 'Yes' : 'No'" readonly />
            <x-field name="IsLocked" label="Locked" :value="$person->IsLocked ? 'Yes' : 'No'" readonly />
        </div>
        @endif
    </x-card>

    <x-card class="user-access-section" id="roles" title="Roles">
        @if ($editing)
        <form method="POST" action="{{ route('app.setup.users.roles.update', ['user' => $person->Id]) }}">
            @csrf @method('PUT')
            <input type="hidden" name="_section" value="roles">
            <input type="hidden" name="roles_form" value="1">
            <div class="field field-wide">
                <label for="user-roles">Assigned roles</label>
                <select id="user-roles" name="roles[]" multiple data-select data-placeholder="No roles assigned">
                    @foreach ($allRoles as $role)
                        <option value="{{ $role->Id }}" @selected(in_array((int) $role->Id, array_map('intval', old('roles_form') ? old('roles', []) : $grantedRoleIds), true))>{{ $role->Name }}</option>
                    @endforeach
                </select>
                <p class="field-help">Permissions from all assigned roles combine with direct grants. Sites and landing pages stay on this person.</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Save roles</button>
                @can('setup.roles.view')<a class="btn" href="{{ route('app.setup.roles.index') }}">Manage roles</a>@endcan
            </div>
        </form>
        @else
        @if ($grantedRoleIds === [])
            <x-empty-state text="No roles assigned. This person uses direct grants." />
        @else
            <ul class="perm-list">
                @foreach ($allRoles->whereIn('Id', $grantedRoleIds) as $role)
                    <li>@can('setup.roles.view')<a href="{{ route('app.setup.roles.show', ['role' => $role->Id]) }}">{{ $role->Name }}</a>@else{{ $role->Name }}@endcan</li>
                @endforeach
            </ul>
        @endif
        @endif
    </x-card>

    <x-card class="user-access-section" id="branches" title="Sites">
        <x-slot:info>
            <p>Which sites this person may see. Everything else is filtered out before a query runs.</p>
            <p><strong>No sites granted means every site.</strong> That is how head office people are set up,
               so nobody has to add each new site as it opens. Grant exactly one site and this person becomes a
               branch user, pinned to it.</p>
            <p>The six administrative entities are listed too — they are branches for accounting and never
               keep a trading day.</p>
        </x-slot:info>
            @if ($editing)
        <form method="POST" action="{{ route('app.setup.users.branches.update', ['user' => $person->Id]) }}">
                @csrf
                @method('PUT')
            <input type="hidden" name="_section" value="branches">

                <div class="field field-wide">
                    <label for="f-branches">Sites granted</label>
                    <select id="f-branches" name="branches[]" multiple data-select
                            data-placeholder="Every site — no individual grant">
                        @foreach ($allBranches as $branch)
                            <option value="{{ $branch->BranchId }}"
                                @selected(in_array((int) $branch->BranchId, array_map('intval', old('_section') === 'branches' ? old('branches', []) : $grantedBranchIds), true))>{{ $branch->Name }}@unless ($branch->IsTrading) · not trading @endunless</option>
                        @endforeach
                    </select>
                    <p class="field-help">
                        No sites selected means every site. Selecting one site switches this person to that branch workspace.
                    </p>
                </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save sites</button>
            </div>
        </form>
        @else
        @if ($branchCount === 0)
            <x-notice tone="info" title="Every site — no individual restrictions" />
        @else
            <ul class="perm-list">
                @foreach ($grantedBranches as $branch)
                    <li>{{ $branch->Name }} @unless ($branch->IsTrading)<x-chip tone="neutral">not trading</x-chip>@endunless</li>
                @endforeach
            </ul>
        @endif
        @endif
    </x-card>

    <x-card class="user-access-section" id="permissions" title="Permissions" collapsible :open="$errors->any() || ! $editing">
        @if ($editing)
        <form method="POST" action="{{ route('app.setup.users.permissions.update', ['user' => $person->Id]) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_section" value="permissions">

            <p class="field-help">Ticks are direct grants. Role grants stay effective until the role is removed.</p>
            @foreach ($permissionRows as $module => $rows)
                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <div class="role-list" data-access-checks>
                    <div class="menu-ticks-bulk"><button type="button" class="btn sm" data-tick-all>Select group</button><button type="button" class="btn sm" data-tick-none>Clear direct grants</button></div>
                    @foreach ($rows as $row)
                        <label class="role-row {{ (old('_section') === 'permissions' ? in_array((int) $row['permission']->Id, array_map('intval', old('permissions', [])), true) : $row['direct']) ? 'on' : '' }}">
                            <input type="checkbox" name="permissions[]" value="{{ $row['permission']->Id }}"
                                   @checked(old('_section') === 'permissions' ? in_array((int) $row['permission']->Id, array_map('intval', old('permissions', [])), true) : $row['direct'])>
                            <span class="role-name">
                                <code>{{ $row['permission']->Code }}</code>
                            </span>
                            <span class="role-primary">{{ $row['permission']->Name }} @if ($row['inherited'])<x-chip tone="info">Granted by role</x-chip>@endif</span>
                        </label>
                    @endforeach
                </div>
            @endforeach

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save permissions</button>
            </div>
        </form>
        @else
        @if ($effective === [])
            <x-empty-state text="No permissions granted. This person cannot use protected actions." />
        @else
            @foreach ($permissionRows as $module => $rows)
                @continue(! collect($rows)->contains(fn ($row) => $row['effective']))
                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <ul class="perm-list">
                    @foreach ($rows as $row)
                        @continue(! $row['effective'])
                        <li>{{ $row['permission']->Name }} <code>{{ $row['permission']->Code }}</code>
                            @if ($row['inherited'])<x-chip tone="info">Granted by role</x-chip>@endif
                            @if ($row['direct'])<x-chip tone="neutral">Direct grant</x-chip>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
        @endif
    </x-card>

    <x-card class="user-access-section" id="menu" title="Menu access" collapsible :open="$errors->any()">
        @if ($editing)
        <form method="POST" action="{{ route('app.setup.users.menu.update', ['user' => $person->Id]) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_section" value="menu">

            <p class="field-help">No direct or role menu ticks means the whole menu.</p>

            @foreach ($menuWorkspaces as $code => $workspace)
                <h4 class="perm-module">{{ $workspace['label'] }}</h4>

                <div class="menu-ticks" data-access-checks>
                    <div class="menu-ticks-bulk">
                        <button type="button" class="btn sm" data-tick-all>Tick all</button>
                        <button type="button" class="btn sm" data-tick-none>Tick none</button>
                    </div>

                    @php($section = null)
                    @foreach ($workspace['rows'] as $row)
                        @if ($section !== $row['section']->Code)
                            @php($section = $row['section']->Code)
                            <p class="menu-ticks-section">{{ $row['section']->Label }}</p>
                        @endif

                        @php($id = (int) $row['item']->Id)
                        <label class="role-row menu-tick {{ in_array($id, array_map('intval', old('_section') === 'menu' ? old('menu', []) : $menuDirectIds), true) ? 'on' : '' }}"
                               style="--depth: {{ $row['depth'] }}">
                            <input type="checkbox" name="menu[]" value="{{ $id }}"
                                   @checked(in_array($id, array_map('intval', old('_section') === 'menu' ? old('menu', []) : $menuDirectIds), true))>
                            <span class="role-name">
                                {{ $row['item']->Label }}
                                @if ($row['item']->PermissionCode)<code>{{ $row['item']->PermissionCode }}</code>@endif
                            </span>
                            <span class="role-primary">
                                {{ $row['item']->isLink() ? 'screen' : 'not built yet' }}
                                @if (in_array($id, $roleMenuIds, true))<x-chip tone="info">Granted by role</x-chip>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endforeach

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save menu access</button>
            </div>
        </form>
        @else
        @if (! $menuRestricted)
            <x-notice tone="info" title="The whole menu — no individual restrictions" />
        @else
            @foreach ($menuWorkspaces as $workspace)
                @php($ticked = collect($workspace['rows'])->filter(fn ($row) => in_array((int) $row['item']->Id, $effectiveMenuIds, true)))
                @continue($ticked->isEmpty())
                <h4 class="perm-module">{{ $workspace['label'] }}</h4>
                <ul class="perm-list">
                    @foreach ($ticked as $row)
                        <li>{{ $row['section']->Label }} · {{ $row['item']->Label }}
                            @if (in_array((int) $row['item']->Id, $roleMenuIds, true))<x-chip tone="info">Granted by role</x-chip>@endif
                            @if (in_array((int) $row['item']->Id, $menuDirectIds, true))<x-chip tone="neutral">Direct grant</x-chip>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
        @endif
    </x-card>

    <x-card class="user-access-section" id="password" title="Password">
        <x-slot:info>
            <p>Three ways into the account, and only one at a time.</p>
            <p>The link goes out the same way as “Forgot your password?”, so it behaves identically.</p>
            <p>There is no field to type a password into, on purpose: a password an administrator chose is one
               an administrator knows, and a temporary one expires the moment they sign in. A cleared password is the state every user migrated from PumpIT arrives in.</p>
        </x-slot:info>

        @if ($editing)
        @if (session('temporary_password'))
            {{-- Shown once and only once: it lives in a flash and is gone on the
                 next request. Refreshing this page does not bring it back, which
                 is the point — a temporary credential you can return to is not
                 temporary. --}}
            <x-notice tone="warn" title="Read this to them now — it is shown only once">
                <p>{{ $person->UserName }} is held on the change-password screen until they replace it with
                   one only they know.</p>
                <p class="temp-password"><code>{{ session('temporary_password') }}</code></p>
            </x-notice>
        @endif

        @if (config('mail.default') === 'log')
            <x-notice tone="warn" collapsible title="Mail is not sending — only a temporary password works">
                <p><code>MAIL_MAILER</code> is <code>log</code>, so a set-password link — and “Forgot your
                   password?” — is written to the log and nobody receives it, until SMTP is configured.</p>
            </x-notice>
        @endif

        <form method="POST" action="{{ route('app.setup.users.password.update', ['user' => $person->Id]) }}">
            @csrf

            <div class="pw-actions">
                <div class="pw-choice">
                    <button type="submit" name="action" value="link" class="btn-primary"
                            @disabled(! $person->EmailAddress)>Send a set-password link</button>
                    <p class="field-help">
                        @if ($person->EmailAddress)
                            Mails {{ $person->EmailAddress }} a link that expires in
                            {{ \Modules\Core\Services\PasswordResetService::LIFETIME_MINUTES }} minutes.
                        @else
                            No email address on this person, so there is nowhere to send one.
                        @endif
                    </p>
                </div>

                <div class="pw-choice">
                    <button type="submit" name="action" value="temporary" class="btn">Set a temporary password</button>
                    <p class="field-help">Shown once; they must change it at their next sign-in.</p>
                </div>

                <div class="pw-choice">
                    <button type="submit" name="action" value="clear" class="btn">Clear their password</button>
                    <p class="field-help">Nobody can sign in as them until they set one themselves.</p>
                </div>
            </div>
        </form>
        @else
        <p>{{ $person->MustChangePassword ? 'A password change is required at the next sign-in.' : 'No password change is required.' }}</p>
        <p class="field-help">Last sign-in: {{ $person->LastSignInAt ? \App\Support\BusinessDate::show($person->LastSignInAt) : 'Never' }}.</p>
        @can('setup.users.edit')
            <a class="btn" href="{{ route('app.setup.users.edit', ['user' => $person->Id]).'#password' }}">Manage password</a>
        @endcan
        @endif
    </x-card>
