<x-app-shell :title="$person->UserName">
    <x-page-head eyebrow="Setup · Users and access" :title="$person->UserName" :blurb="$person->EmailAddress">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.users.index') }}">Back to the list</a>
            @if ($editing)
                <a class="btn" href="{{ route('app.setup.users.show', ['user' => $person->Id]) }}">View user</a>
            @else
                @can('setup.users.edit')<a class="btn-primary" href="{{ route('app.setup.users.edit', ['user' => $person->Id]) }}">Edit user</a>@endcan
            @endif
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    @if ($errors->any())
        <x-notice tone="warn" title="Changes not saved">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-notice>
    @endif
    @if (! $person->IsActive || $person->IsLocked)
        <x-notice tone="warn" title="Sign-in unavailable">{{ ! $person->IsActive ? 'This account is inactive.' : '' }} {{ $person->IsLocked ? 'This account is locked.' : '' }}</x-notice>
    @endif
    <x-statstrip :stats="[
        ['label' => 'Account', 'value' => ! $person->IsActive ? 'Inactive' : ($person->IsLocked ? 'Locked' : 'Active')],
        ['label' => 'Type', 'value' => $person->UserType === 'branch' ? 'Branch' : ($person->UserType === 'ho' ? 'Head office' : 'Not set')],
        ['label' => 'Sites', 'value' => $branchCount === 0 ? 'Every site' : $branchCount],
        ['label' => 'Roles', 'value' => count($grantedRoleIds)],
        ['label' => 'Last sign-in', 'value' => $person->LastSignInAt ? \App\Support\BusinessDate::show($person->LastSignInAt) : 'Never'],
    ]" />
    @if ($editing)<p class="field-help">Editing user · Save each section separately. Roles and direct grants combine.</p>@else<p class="field-help">Viewing user · Access below combines roles and direct grants.</p>@endif
    <x-tabs chips active="none" label="User sections" :items="[
        ['key' => 'details', 'label' => 'Account details', 'href' => '#details'],
        ['key' => 'roles', 'label' => 'Roles', 'href' => '#roles'],
        ['key' => 'branches', 'label' => 'Sites', 'href' => '#branches'],
        ['key' => 'permissions', 'label' => 'Permissions', 'href' => '#permissions'],
        ['key' => 'menu', 'label' => 'Menu access', 'href' => '#menu'],
        ['key' => 'password', 'label' => 'Password', 'href' => '#password'],
    ]" />
    @include('core::setup.users._sections')
    <script>
        document.querySelectorAll('[data-access-checks]').forEach(function (group) {
            function setAll(on) {
                group.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                    box.checked = on;
                    box.closest('.role-row')?.classList.toggle('on', on);
                });
            }
            group.querySelector('[data-tick-all]')?.addEventListener('click', function () { setAll(true); });
            group.querySelector('[data-tick-none]')?.addEventListener('click', function () { setAll(false); });
            group.addEventListener('change', function (event) {
                if (event.target.matches('input[type="checkbox"]')) event.target.closest('.role-row')?.classList.toggle('on', event.target.checked);
            });
        });
        function openUserSection() {
            const id = location.hash.slice(1);
            if (!['details', 'roles', 'branches', 'permissions', 'menu', 'password'].includes(id)) return;
            const section = document.getElementById(id);
            if (section?.matches('details')) section.open = true;
            if (section) requestAnimationFrame(function () { section.scrollIntoView({ block: 'start' }); });
        }
        document.querySelectorAll('.tabset a[href^="#"]').forEach(function (link) {
            link.addEventListener('click', function () { requestAnimationFrame(openUserSection); });
        });
        window.addEventListener('hashchange', openUserSection);
        openUserSection();
    </script>
</x-app-shell>
