@php($edit = $writable && ! $employee->IsSystemAccount)
<x-app-shell :title="$employee->EmployeeName">
    <x-page-head eyebrow="Setup · People and assets · Employees" :title="$employee->EmployeeName"
                 :blurb="$employee->EmployeeCode.' · '.($employee->BranchName ?? 'Branch '.$employee->BranchId)">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.employees.index', ['branches' => [$employee->BranchId]]) }}">Back to employees</a>
            @if ($edit)
                @canany(['people.employees.edit', 'people.areas.edit'])
                    <a class="btn-primary" href="{{ route('app.setup.employees.edit', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode]) }}">Edit employee</a>
                @endcanany
            @endif
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not put back">{{ $message }}</x-notice>@enderror
    @error('Reason')<x-notice tone="warn" title="Not put back">{{ $message }}</x-notice>@enderror
    @include('people::employees.detail')
    @include('people::employees.changes')
</x-app-shell>
