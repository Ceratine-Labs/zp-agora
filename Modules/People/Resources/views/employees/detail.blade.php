@php
    use App\Support\Format;
    $date = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('j M Y') : null;
    $yes = fn ($value) => $value ? 'Yes' : 'No';
    $show = route('app.setup.employees.show', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode]);
@endphp
<x-card :title="$employee->EmployeeName" :sub="$employee->EmployeeCode.' · '.($employee->BranchName ?? 'Branch '.$employee->BranchId)">
    <x-slot:actions>
        @unless (request()->routeIs('app.setup.employees.show'))<a class="btn sm" href="{{ $show }}">Open</a>@endunless
    </x-slot:actions>
    <x-facts>
        <x-fact label="Status" :value="$employee->Status" :tone="$employee->IsActive ? 'good' : 'warn'">
            {{ $employee->IsActive ? 'On PumpIT\'s sign-on lists.' : 'Inactive in PumpIT. Still named on every shift they worked.' }}
        </x-fact>
        <x-fact label="Post" :value="$employee->PostDescription ?? 'Post '.$employee->PostNo">
            Post {{ $employee->PostNo }} at this site{{ $employee->PostStockControl ? ' · carries stock losses' : '' }}.
        </x-fact>
        <x-fact label="Known as" :value="$employee->NickName" />
        <x-fact label="Title and initials" :value="trim(($employee->Title ?? '').' '.($employee->Initials ?? '')) ?: null" />
        <x-fact label="First name" :value="$employee->FirstName" />
        <x-fact label="Surname" :value="$employee->Surname" />
        <x-fact label="Pump attendant" :value="$yes($employee->IsPumpAttendant)" />
        <x-fact label="Joined" :value="$date($employee->DateJoined)">As PumpIT holds it.</x-fact>
        <x-fact label="Departed" :value="$date($employee->DateDepart)">As PumpIT holds it; the status is IsActive, not this date.</x-fact>
        <x-fact label="Cash device code" :value="$employee->CashDeviceCode" />
        <x-fact label="PumpIT access" :value="collect(['sign in' => $employee->CanSignIn, 'manager' => $employee->CanAccessManager, 'setup' => $employee->CanAccessSetup])->filter()->keys()->implode(', ') ?: 'None'" />
        @if ($employee->IsSystemAccount)
            <x-fact label="System account" value="Yes">A PUMPIT / ADMIN row, left out of the employee list by default.</x-fact>
        @endif
    </x-facts>
</x-card>

<x-card title="Counting areas" :sub="$areas->count().' this person may be signed on to'">
    @if ($areas->isEmpty())
        <x-empty-state text="None. PumpIT's employee areas (BRN_EmployeeArea) name no area for this person at this site." />
    @else
        <ul class="perm-list">
            @foreach ($areas as $area)
                <li>{{ $area->AreaDescription ?? 'Area '.$area->AreaNo }} <x-chip tone="neutral" :dot="false">area {{ $area->AreaNo }}</x-chip></li>
            @endforeach
        </ul>
    @endif
</x-card>

<x-card title="Shifts worked" :sub="$shiftSummary->isEmpty() ? 'None recorded' : $shiftSummary->map(fn ($s) => Format::n($s->Shifts).' '.strtolower($s->Kind).($s->Shifts == 1 ? '' : 's').', '.$date($s->FirstDay).' – '.$date($s->LastDay))->implode(' · ')">
    @if ($shifts->isEmpty())
        <x-empty-state text="No cashup or stock count names this person." />
    @else
        <x-table layout-key="People.employees.shifts" procedure="agora.usp_People_GetEmployee" :count="$shifts->count()" tools>
            <thead><tr><th>Day</th><th>What</th><th class="num">Shift</th><th class="num">Till</th><th>Area</th></tr></thead>
            <tbody>@foreach ($shifts as $shift)
                <tr>
                    <td data-sort="{{ $shift->TransactionDate }}">{{ $date($shift->TransactionDate) }}</td>
                    <td>{{ $shift->Kind }}</td>
                    <td class="num">{{ $shift->ShiftNo ?? '—' }}</td>
                    <td class="num">{{ $shift->TillNo ?? '—' }}</td>
                    <td>{{ $shift->AreaDescription ?? ($shift->AreaNo ? 'Area '.$shift->AreaNo : '—') }}</td>
                </tr>
            @endforeach</tbody>
        </x-table>
        <p class="muted">The {{ $shifts->count() }} most recent. Cashups are BRN_DailyBanking rows with this person as the cashier; stock counts are STK_StockReconEmployees sign-ons.</p>
    @endif
</x-card>
