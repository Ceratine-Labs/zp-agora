<x-app-shell :title="$employee->EmployeeName">
    <x-page-head eyebrow="Setup · People and assets · Employees" :title="$employee->EmployeeName"
                 :blurb="$employee->EmployeeCode.' · '.($employee->BranchName ?? 'Branch '.$employee->BranchId)">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.employees.index', ['branches' => [$employee->BranchId]]) }}">Back to employees</a>
        </x-slot:actions>
    </x-page-head>
    @include('people::employees.detail')
</x-app-shell>
