{{--
    One person, and what they may do (T028).

    Two halves, deliberately: what you CHOOSE (the roles) and what that
    CHOICE MEANS (the effective permissions). A role list on its own does not
    answer "why can this person see the recon screen", and that is the question
    somebody actually asks.
--}}
<x-app-shell :title="$person->UserName">
    <x-page-head
        eyebrow="Setup · Users and access"
        :title="$person->UserName"
        :blurb="$person->EmailAddress ?? 'No email address on the legacy row — this person cannot sign in until one is supplied.'">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.users.index') }}">Back to the list</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @if ($person->MustChangePassword)
        <x-notice tone="warn" title="Must reset their password">
            This person signed in with a password somebody else set, or has never signed in at all.
            They are held on the change-password screen until they choose one.
        </x-notice>
    @endif

    <x-statstrip :stats="[
        ['label' => 'Code', 'value' => $person->UserCode ?? \App\Support\Format::NOTHING],
        ['label' => 'Type', 'value' => $person->UserType === 'branch' ? 'Branch' : ($person->UserType === 'ho' ? 'Head office' : \App\Support\Format::NOTHING), 'note' => $person->UserType === null ? 'No branch grants — undecided' : null],
        ['label' => 'Branches granted', 'value' => \App\Support\Format::n($branchCount, 0), 'note' => 'Edited in T009'],
        ['label' => 'Last sign-in', 'value' => $person->LastSignInAt ? \App\Support\Format::datetime($person->LastSignInAt) : 'Never'],
        ['label' => 'Legacy type', 'value' => $person->LegacyUserType ?? \App\Support\Format::NOTHING, 'note' => 'From PumpIT'],
    ]" />

    <div class="two-col">
        <x-card title="Roles" sub="What this person is. The primary role decides where they land after signing in.">
            <form method="POST" action="{{ route('app.setup.users.update', ['user' => $person->Id]) }}">
                @csrf
                @method('PUT')

                <div class="role-list">
                    @foreach ($roles as $role)
                        @php($isHeld = $held->has($role->Id))
                        <label class="role-row {{ $isHeld ? 'on' : '' }}">
                            <input type="checkbox" name="roles[]" value="{{ $role->Id }}" @checked($isHeld)
                                   @cannot('setup.users.edit') disabled @endcannot>
                            <span class="role-name">
                                {{ $role->Name }}
                                @if ($role->IsReadOnly)<x-chip tone="neutral">read only</x-chip>@endif
                            </span>
                            <span class="role-primary">
                                <input type="radio" name="primary" value="{{ $role->Id }}"
                                       @checked($held->get($role->Id)?->IsPrimary)
                                       @cannot('setup.users.edit') disabled @endcannot>
                                primary
                            </span>
                        </label>
                    @endforeach
                </div>

                @can('setup.users.edit')
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save roles</button>
                    </div>
                @else
                    <x-notice tone="info" title="Read only">
                        You may see who holds what, but changing it needs <code>setup.users.edit</code>.
                    </x-notice>
                @endcan
            </form>
        </x-card>

        <x-card title="What that lets them do"
                sub="Resolved through every role above. This is the answer to “why can they see that”."
                collapsible open>
            @if ($effective === [])
                <x-empty-state text="No roles, so no permissions. This person can sign in and see nothing." />
            @else
                @foreach ($effective as $module => $codes)
                    <h4 class="perm-module">{{ ucfirst($module) }}</h4>
                    <ul class="perm-list">
                        @foreach ($codes as $code)
                            <li><code>{{ $code }}</code></li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </x-card>
    </div>
</x-app-shell>
