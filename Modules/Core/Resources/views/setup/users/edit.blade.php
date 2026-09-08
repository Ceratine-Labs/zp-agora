{{--
    Changing one person (T028).

    FOUR CARDS, FOUR SAVE BUTTONS. Each card owns a complete set and each Save
    replaces that set rather than adding to it — a grant removed is the change
    that matters, and a diff that only ever adds is how everybody ends up an
    administrator. That only works if the form submits everything it owns,
    which is why the sets are not merged behind one button: one refusal would
    then discard three cards' worth of work, and "the sites card was not on
    this form" would be indistinguishable from "grant no sites", which means
    grant EVERY site.

    Each card carries an id so a save can put the reader back where they were —
    this page is taller than a laptop.
--}}
<x-app-shell :title="'Edit '.$person->UserName">
    <x-page-head
        eyebrow="Setup · Users and access"
        :title="'Edit '.$person->UserName"
        blurb="Who they are, what they may do, and which sites they may see. Each block saves on its own.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.users.show', ['user' => $person->Id]) }}">Back to the view</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    {{-- The three refusals UserAccessService can make are decisions, not
         faults, so they arrive as errors on the card that raised them. --}}
    @foreach (['details' => 'Details', 'roles' => 'Roles', 'branches' => 'Sites', 'permissions' => 'Extra permissions', 'password' => 'Password'] as $card => $label)
        @error($card)
            <x-notice tone="warn" title="{{ $label }} not saved">{{ $message }}</x-notice>
        @enderror
    @endforeach

    <x-card id="details" title="Details" sub="Who this person is, and whether they may sign in at all.">
        <form method="POST" action="{{ route('app.setup.users.details.update', ['user' => $person->Id]) }}">
            @csrf
            @method('PUT')

            <div class="field-row">
                <x-field name="UserName" label="Name" :value="old('UserName', $person->UserName)"
                         help="What the footer and every audit row calls them." />
                <x-field name="EmailAddress" label="Email address" type="email"
                         :value="old('EmailAddress', $person->EmailAddress)"
                         help="The sign-in identity, and where a set-password link goes. Required — the column is NOT NULL and an account without one cannot be used." />
                <x-field name="UserCode" label="Code" :value="old('UserCode', $person->UserCode)"
                         help="dbo.sp_csGetUser returns Autoidx as this — the number the customer says out loud." />
            </div>

            <div class="field-row">
                {{-- Only the sites they are granted — the controller says why. --}}
                <x-field name="HomeBranchId" label="Home site"
                         :value="old('HomeBranchId', $person->HomeBranchId)"
                         :choices="$homeBranchChoices"
                         help="Set this and the person works in the branch workspace, at that site. Granting exactly one site sets it for you." />
                <x-field name="IsActive" label="Active" type="bool" :value="old('IsActive', $person->IsActive)"
                         placeholder="May sign in"
                         help="Somebody who has left is deactivated, never deleted — the rows they wrote stay attributable." />
                <x-field name="IsLocked" label="Locked" type="bool" :value="old('IsLocked', $person->IsLocked)"
                         placeholder="Locked out"
                         help="Carried over from SS_Users.isLocked. A locked account is refused at sign-in." />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save details</button>
            </div>
        </form>
    </x-card>

    <x-card id="password" title="Password" sub="How this person gets into their account. Three ways, and only ever one of them at a time.">
        @if (session('temporary_password'))
            {{-- Shown once and only once: it lives in a flash and is gone on the
                 next request. Refreshing this page does not bring it back, which
                 is the point — a temporary credential you can return to is not
                 temporary. --}}
            <x-notice tone="warn" title="Read this to them now">
                <p>This is the only time it is shown. It is not stored anywhere you can look it up,
                   and {{ $person->UserName }} is held on the change-password screen until they
                   replace it with one only they know.</p>
                <p class="temp-password"><code>{{ session('temporary_password') }}</code></p>
            </x-notice>
        @endif

        @if (config('mail.default') === 'log')
            <x-notice tone="warn" title="Mail is not sending">
                <code>MAIL_MAILER</code> is <code>log</code>, so a set-password link is written to
                <code>storage/logs/laravel.log</code> and nobody receives it. That is true of
                “Forgot your password?” as well — which is the only way in for everyone migrated
                from PumpIT. Until SMTP is configured, <strong>a temporary password is the only
                thing here that actually works.</strong>
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
                            The same channel as “Forgot your password?”, so the link behaves identically.
                        @else
                            No email address on this person, so there is nowhere to send one.
                        @endif
                    </p>
                </div>

                <div class="pw-choice">
                    <button type="submit" name="action" value="temporary" class="btn">Set a temporary password</button>
                    <p class="field-help">
                        Generates one, shows it once, and forces a change at their next sign-in. There is
                        no field to type a password into on purpose — a password an administrator chose
                        is one an administrator knows, and this one expires the moment they sign in.
                    </p>
                </div>

                <div class="pw-choice">
                    <button type="submit" name="action" value="clear" class="btn">Clear their password</button>
                    <p class="field-help">
                        Puts the account beyond signing in until they set one themselves. This is the state
                        every migrated user arrives in.
                    </p>
                </div>
            </div>
        </form>
    </x-card>

    <div class="two-col">
        <x-card id="roles" title="Roles" sub="What this person is. The primary role decides where they land after signing in.">
            <form method="POST" action="{{ route('app.setup.users.update', ['user' => $person->Id]) }}">
                @csrf
                @method('PUT')

                <div class="role-list">
                    @foreach ($roles as $role)
                        @php($isHeld = $held->has($role->Id))
                        <label class="role-row {{ $isHeld ? 'on' : '' }}">
                            <input type="checkbox" name="roles[]" value="{{ $role->Id }}" @checked($isHeld)>
                            <span class="role-name">
                                {{ $role->Name }}
                                @if ($role->IsReadOnly)<x-chip tone="neutral">read only</x-chip>@endif
                            </span>
                            <span class="role-primary">
                                <input type="radio" name="primary" value="{{ $role->Id }}"
                                       @checked($held->get($role->Id)?->IsPrimary)>
                                primary
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Save roles</button>
                </div>
            </form>
        </x-card>

        <x-card id="branches" title="Sites" sub="Which of the estate this person may see. Everything else is filtered out before a query runs.">
            <form method="POST" action="{{ route('app.setup.users.branches.update', ['user' => $person->Id]) }}">
                @csrf
                @method('PUT')

                <x-notice tone="info" title="Granting nothing grants everything">
                    An empty list means every site. Head office people are granted nothing individually,
                    because granting them every site would have to be maintained as sites open — so
                    absence of rows is the grant, and both the scope bar and the branch scope read it
                    that way. Grant exactly one site and this person becomes a branch user, pinned to it.
                </x-notice>

                <div class="field field-wide">
                    <label for="f-branches">Sites granted</label>
                    <select id="f-branches" name="branches[]" multiple data-select
                            data-placeholder="Every site — no individual grant">
                        @foreach ($allBranches as $branch)
                            <option value="{{ $branch->BranchId }}"
                                @selected(in_array((int) $branch->BranchId, $grantedBranchIds, true))>{{ $branch->Name }}@unless ($branch->IsTrading) · not trading @endunless</option>
                        @endforeach
                    </select>
                    <p class="field-help">
                        {{ $branchCount === 0 ? 'Currently every site.' : $branchCount.' granted.' }}
                        The six administrative entities are listed too — they are branches for accounting
                        and never keep a trading day.
                    </p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Save sites</button>
                </div>
            </form>
        </x-card>
    </div>

    <x-card id="permissions" title="Extra permissions"
            sub="Granted to this person by name, beside whatever their roles carry."
            collapsible :open="$directCount > 0">
        <form method="POST" action="{{ route('app.setup.users.permissions.update', ['user' => $person->Id]) }}">
            @csrf
            @method('PUT')

            <x-notice tone="info" title="For the exception, not the shape">
                Six roles cover the shape of the business; these cover the exceptions — the controller who
                must also reverse a reconciliation, without being handed everything else an administrator
                holds. Grants here are <strong>additive only</strong>: there is no deny, so a permission
                somebody must not have is removed by taking away the role that carries it. A row marked
                <em>via role</em> is already held and ticking it changes nothing.
            </x-notice>

            @foreach ($permissionRows as $module => $rows)
                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <div class="role-list">
                    @foreach ($rows as $row)
                        <label class="role-row {{ $row['direct'] ? 'on' : '' }}">
                            <input type="checkbox" name="permissions[]" value="{{ $row['permission']->Id }}"
                                   @checked($row['direct'])>
                            <span class="role-name">
                                <code>{{ $row['permission']->Code }}</code>
                                @if ($row['viaRole'])<x-chip tone="neutral">via role</x-chip>@endif
                            </span>
                            <span class="role-primary">{{ $row['permission']->Name }}</span>
                        </label>
                    @endforeach
                </div>
            @endforeach

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save permissions</button>
            </div>
        </form>
    </x-card>
</x-app-shell>
