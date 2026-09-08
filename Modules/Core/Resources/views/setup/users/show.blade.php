{{--
    One person, read only (T028).

    feature-rules §5: a view screen carries Back to the index and Edit where the
    reader has the permission. Nothing here saves — the Auditor holds *.*.view
    and must be able to read this page without any control on it being one
    misclick from a grant.

    Four questions, in the order somebody actually asks them: who are they,
    what are they, which sites can they see, and what does all that let them
    do. The last card is the one that matters — a role list does not answer
    "why can this person open the recon screen", and that is the question.
--}}
<x-app-shell :title="$person->UserName">
    <x-page-head
        eyebrow="Setup · Users and access"
        :title="$person->UserName"
        {{-- Not `?? 'no address'`: agora.User.EmailAddress is NOT NULL and the
             address is the sign-in identity, so there is no such row to
             describe. The edit screen requires one for the same reason. --}}
        :blurb="$person->EmailAddress">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.users.index') }}">Back to the list</a>
            @can('setup.users.edit')
                <a class="btn-primary" href="{{ route('app.setup.users.edit', ['user' => $person->Id]) }}">Edit</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @if (! $person->IsActive || $person->IsLocked)
        <x-notice tone="warn" title="{{ $person->IsLocked ? 'Locked' : 'Deactivated' }}">
            This person cannot sign in.
            {{ $person->IsLocked
                ? 'The account is locked; clearing it is on the edit screen.'
                : 'The account is deactivated, which is how somebody who has left is retired — the rows they wrote stay.' }}
        </x-notice>
    @endif

    @if ($person->MustChangePassword)
        <x-notice tone="warn" title="Must reset their password">
            This person signed in with a password somebody else set, or has never signed in at all.
            They are held on the change-password screen until they choose one.
        </x-notice>
    @endif

    <x-statstrip :stats="[
        ['label' => 'Code', 'value' => $person->UserCode ?? \App\Support\Format::NOTHING],
        ['label' => 'Type', 'value' => $person->UserType === 'branch' ? 'Branch' : ($person->UserType === 'ho' ? 'Head office' : \App\Support\Format::NOTHING), 'note' => $person->UserType === null ? 'No branch grants — sees every site' : null],
        ['label' => 'Sites granted', 'value' => $branchCount === 0 ? 'Every site' : \App\Support\Format::n($branchCount, 0)],
        ['label' => 'Extra permissions', 'value' => \App\Support\Format::n($directCount, 0), 'note' => 'Beside their roles'],
        {{-- Carbon, not Format: App\Support\Format is money, volumes and
             percentages and has never had a date method. The written form is
             the grid's own (grid/_cell.blade.php), so the list and this screen
             cannot disagree about what a timestamp looks like. --}}
        ['label' => 'Last sign-in', 'value' => $person->LastSignInAt?->format('Y-m-d H:i') ?? 'Never'],
        ['label' => 'Legacy type', 'value' => $person->LegacyUserType ?? \App\Support\Format::NOTHING, 'note' => 'From PumpIT'],
    ]" />

    <div class="two-col">
        <x-card title="Roles" sub="What this person is. The primary role decides where they land after signing in.">
            @php($heldRoles = $roles->filter(fn ($role) => $held->has($role->Id)))

            @if ($heldRoles->isEmpty())
                <x-empty-state text="No roles. This person can sign in and see nothing." />
            @else
                <ul class="perm-list">
                    @foreach ($heldRoles as $role)
                        <li>
                            {{ $role->Name }}
                            @if ($held->get($role->Id)?->IsPrimary)<x-chip tone="good">primary</x-chip>@endif
                            @if ($role->IsReadOnly)<x-chip tone="neutral">read only</x-chip>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Sites" sub="Which of the estate this person may see. Everything else is filtered out before a query runs.">
            @if ($branchCount === 0)
                <x-notice tone="info" title="Every site">
                    No site is granted individually, and that IS the grant — head office people are not
                    given every site to maintain as new ones open. The scope bar offers the whole estate.
                </x-notice>
            @else
                <ul class="perm-list">
                    @foreach ($grantedBranches as $branch)
                        <li>{{ $branch->Name }} @unless ($branch->IsTrading)<x-chip tone="neutral">not trading</x-chip>@endunless</li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <x-card title="What that lets them do"
            sub="Resolved through every role above, plus anything granted by name. This is the answer to “why can they see that”."
            collapsible open>
        @if ($effective === [])
            <x-empty-state text="No roles and no direct grants, so no permissions. This person can sign in and see nothing." />
        @else
            @foreach ($permissionRows as $module => $rows)
                @php($holdsAny = collect($rows)->contains(fn ($row) => $row['direct'] || $row['viaRole']))
                @continue(! $holdsAny)

                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <ul class="perm-list">
                    @foreach ($rows as $row)
                        @continue(! $row['direct'] && ! $row['viaRole'])
                        <li>
                            <code>{{ $row['permission']->Code }}</code>
                            @if ($row['direct'])<x-chip tone="warn">granted by name</x-chip>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </x-card>
</x-app-shell>
