@php
    $show = route('app.setup.employees.show', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode]);
    $postChoices = $posts->mapWithKeys(fn ($p) => [$p->PostNo => $p->PostDescription.' (post '.$p->PostNo.')'])->all();
    if (! array_key_exists($employee->PostNo, $postChoices)) {
        $postChoices = [$employee->PostNo => 'Post '.$employee->PostNo.' (not on this site\'s list)'] + $postChoices;
    }
    $assigned = $areas->where('Assigned', true)->pluck('AreaNo')->map(fn ($n) => (int) $n)->values()->all();
    $ticked = array_map('intval', old('Areas', $assigned));
@endphp
<x-app-shell :title="'Edit '.$employee->EmployeeName">
    <x-page-head eyebrow="Setup · People and assets · Employees" :title="'Edit '.$employee->EmployeeName"
                 :blurb="$employee->EmployeeCode.' · '.($employee->BranchName ?? 'Branch '.$employee->BranchId)">
        <x-slot:info>
            <p>These changes are written to PumpIT — BRN_Employee and BRN_EmployeeArea — because PumpIT's tills and stock counts sign people on from them. Each value is recorded first, with your reason, so the change can be put back from the employee's page.</p>
            <p>The name and the code are not changed here.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ $show }}">Back to employee</a></x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    @can('people.employees.edit')
        <form method="POST" action="{{ route('app.setup.employees.update', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode]) }}"
              data-confirm="Write this to PumpIT?" data-confirm-action="Save employee"
              data-confirm-text="PumpIT's BRN_Employee is changed for {{ $employee->EmployeeCode }} {{ $employee->EmployeeName }} at {{ $employee->BranchName ?? 'branch '.$employee->BranchId }}. Each change and your reason are recorded, and can be put back."
              data-diff="PumpIT BRN_Employee · {{ $employee->EmployeeCode }} {{ $employee->EmployeeName }} at {{ $employee->BranchName ?? 'branch '.$employee->BranchId }}" data-diff-note="Each change and your reason are recorded, and can be put back.">
            @csrf @method('PUT')
            <input type="hidden" name="ExpectedIsActive" value="{{ (int) $employee->IsActive }}">
            <input type="hidden" name="ExpectedPostNo" value="{{ $employee->PostNo }}">
            <input type="hidden" name="ExpectedNickName" value="{{ $employee->NickName }}">
            <x-card title="The person" sub="Status, post and the name the site knows them by">
                <div class="field-row">
                    <x-field name="IsActive" type="bool" label="Active" placeholder="Active — on PumpIT's sign-on lists"
                             data-diff-field="IsActive" :data-diff-old="(int) $employee->IsActive" data-diff-on="Active" data-diff-off="Inactive"
                             :value="(bool) old('IsActive', $employee->IsActive)" help="Inactive is how PumpIT records a person who has left. They stay on every shift they worked." />
                    <x-field name="PostNo" label="Post" :choices="$postChoices" :value="old('PostNo', $employee->PostNo)" help="The posts at this site."
                             data-diff-field="PostNo" :data-diff-old="$employee->PostNo" />
                    <x-field name="NickName" label="Known as" :value="old('NickName', $employee->NickName)" help="Up to 35 characters."
                             data-diff-field="NickName" :data-diff-old="(string) $employee->NickName" />
                </div>
                <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least 10 characters. Kept with every value you change." />
                <div class="form-actions"><button class="btn-primary" type="submit">Save employee</button></div>
            </x-card>
        </form>
    @endcan

    @can('people.areas.edit')
        <form method="POST" action="{{ route('app.setup.employees.areas.update', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode]) }}"
              data-confirm="Write this to PumpIT?" data-confirm-action="Save areas"
              data-confirm-text="PumpIT's BRN_EmployeeArea is changed for {{ $employee->EmployeeCode }} {{ $employee->EmployeeName }} at {{ $employee->BranchName ?? 'branch '.$employee->BranchId }}. Each area added or removed is recorded, and can be put back."
              data-diff="PumpIT BRN_EmployeeArea · {{ $employee->EmployeeCode }} {{ $employee->EmployeeName }} at {{ $employee->BranchName ?? 'branch '.$employee->BranchId }}" data-diff-note="Each area added or removed is recorded, and can be put back.">
            @csrf @method('PUT')
            <input type="hidden" name="ExpectedAreas" value="{{ json_encode($assigned) }}">
            <x-card title="Counting areas" :sub="count($assigned).' of '.$areas->count().' at this site'">
                @if ($areas->isEmpty())
                    <x-empty-state text="This site has no counting areas (STK_Area)." />
                @else
                    <div class="role-list">
                        @foreach ($areas as $area)
                            <label class="role-row">
                                <input type="checkbox" name="Areas[]" value="{{ $area->AreaNo }}" @checked(in_array((int) $area->AreaNo, $ticked, true))
                                       data-diff-area="{{ $area->AreaDescription ?? 'Area '.$area->AreaNo }}" data-diff-old="{{ $area->Assigned ? 1 : 0 }}">
                                <span class="role-name">{{ $area->AreaDescription ?? 'Area '.$area->AreaNo }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
                <x-field name="AreaReason" label="Reason for the change" :value="old('AreaReason')" help="At least 10 characters." />
                <div class="form-actions"><button class="btn-primary" type="submit">Save areas</button></div>
            </x-card>
        </form>
    @endcan
    @include('people::partials.confirm-diff')
</x-app-shell>
