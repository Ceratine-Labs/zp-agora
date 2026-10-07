{{--
    One person, read only (T028).

    feature-rules §5: a view screen carries Back to the index and Edit where the
    reader has the permission. Nothing here saves — the Auditor holds *.*.view
    and must be able to read this page without any control on it being one
    misclick from a grant.

    Three questions, in the order somebody actually asks them: who are they,
    which sites can they see, and what may they do. Roles were retired on
    22 September 2026, so "what may this person do" now has exactly one answer
    and one place it comes from — which is the thing a role list never managed
    to say plainly.
--}}
<x-app-shell :title="$person->UserName">
    {{-- The blurb is the address. Not `?? 'no address'`: agora.User.EmailAddress
         is NOT NULL and the address is the sign-in identity, so there is no
         such row to describe. The edit screen requires one for the same reason.
         What the menu and permission cards mean sits behind the info button
         (Ryan, 4 Oct 2026); the Sites card carries its own. --}}
    <x-page-head
        eyebrow="Setup · Users and access"
        :title="$person->UserName"
        :blurb="$person->EmailAddress">
        <x-slot:info>
            <p><strong>Menu access</strong> is which menu entries this person sees, and so may open. Nothing
               ticked means the whole menu. An entry that needs a permission they do not hold stays hidden
               either way.</p>
            <p><strong>Permissions</strong> are granted to the person by name — there is no other source. The
               legacy type is carried over from PumpIT.</p>
        </x-slot:info>
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
            @if ($person->IsLocked) The lock is cleared on the edit screen. @endif
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
        ['label' => 'Permissions', 'value' => \App\Support\Format::n($directCount, 0)],
        {{-- Carbon, not Format: App\Support\Format is money, volumes and
             percentages and has never had a date method. The written form is
             the grid's own (grid/_cell.blade.php), so the list and this screen
             cannot disagree about what a timestamp looks like. --}}
        ['label' => 'Last sign-in', 'value' => $person->LastSignInAt ? \App\Support\BusinessDate::show($person->LastSignInAt) : 'Never'],
        ['label' => 'Legacy type', 'value' => $person->LegacyUserType ?? \App\Support\Format::NOTHING],
    ]" />

    <x-card title="Sites">
        <x-slot:info>
            <p>Which sites this person may see. Everything else is filtered out before a query runs.</p>
            <p>No site granted means every site: head office people are not given each site one by one to
               keep up as new ones open.</p>
        </x-slot:info>
            @if ($branchCount === 0)
                <x-notice tone="info" title="Every site — none granted individually" />
            @else
                <ul class="perm-list">
                    @foreach ($grantedBranches as $branch)
                        <li>{{ $branch->Name }} @unless ($branch->IsTrading)<x-chip tone="neutral">not trading</x-chip>@endunless</li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card title="Menu access" collapsible :open="$menuRestricted">
        @if (! $menuRestricted)
            <x-notice tone="info" title="The whole menu — nothing ticked" />
        @else
            @foreach ($menuWorkspaces as $code => $workspace)
                @php($ticked = collect($workspace['rows'])->filter(fn ($row) => in_array((int) $row['item']->Id, $menuDirectIds, true)))
                @continue($ticked->isEmpty())

                <h4 class="perm-module">{{ $workspace['label'] }}</h4>
                <ul class="perm-list">
                    @foreach ($ticked as $row)
                        <li>{{ $row['section']->Label }} · {{ $row['item']->Label }}</li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </x-card>

    <x-card title="What that lets them do" collapsible open>
        @if ($effective === [])
            <x-empty-state text="Nothing is granted, so this person can sign in and do nothing." />
        @else
            @foreach ($permissionRows as $module => $rows)
                @php($holdsAny = collect($rows)->contains(fn ($row) => $row['direct']))
                @continue(! $holdsAny)

                <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                <ul class="perm-list">
                    @foreach ($rows as $row)
                        @continue(! $row['direct'])
                        <li><code>{{ $row['permission']->Code }}</code></li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </x-card>
</x-app-shell>
