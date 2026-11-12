{{-- What Agora has written to PumpIT for this person, a press at a time,
     each with Put back where the caller may and it is still in force. --}}
@php
    $canReverse = $writable && (auth()->user()?->can('people.employees.edit') || auth()->user()?->can('people.areas.edit'));
    $label = fn ($c) => match ($c->Verb) {
        'employee' => match ($c->Field) { 'IsActive' => 'Status', 'PostNo' => 'Post', 'NickName' => 'Known as', default => $c->Field },
        'area-add' => 'Area added', 'area-remove' => 'Area removed', default => $c->Verb,
    };
    // Put back is offered on the NEWEST press still in force only: presses are
    // undone in the order they were made, and an older one over a newer
    // change would be refused as MOVED anyway.
    $newest = $changes->filter(fn ($rows) => $rows->first()->State === 'active')->keys()->first();
    $value = fn ($c, $v) => $c->Field === 'IsActive' ? ($v === '1' ? 'Active' : ($v === '0' ? 'Inactive' : $v)) : ($v ?? '—');
@endphp
<x-card title="Changes written to PumpIT" :sub="$changes->isEmpty() ? 'None yet' : $changes->count().' press(es), newest first'">
    @if ($changes->isEmpty())
        <x-empty-state text="Agora has not changed this person in PumpIT." />
    @else
        @foreach ($changes as $writeId => $rows)
            @php($first = $rows->first())
            <x-table layout-key="People.employees.changes" procedure="agora.usp_People_GetEmployeeChanges" :count="$rows->count()" dense>
                <x-slot:head><tr><th>What</th><th>Before</th><th>After</th></tr></x-slot:head>
                @foreach ($rows as $c)
                    <tr>
                        <td>{{ $label($c) }}{{ $c->AreaNo !== null ? ' · '.($c->AreaDescription ?? 'area '.$c->AreaNo) : '' }}</td>
                        <td>{{ $value($c, $c->PriorValue) }}</td>
                        <td>{{ $value($c, $c->NewValue) }}</td>
                    </tr>
                @endforeach
            </x-table>
            <p class="muted">
                {{ \App\Support\BusinessDate::show($first->CreatedAt) }} · {{ $first->ChangedByName ?? $first->CreatedBy }} · “{{ $first->Reason }}”
                @if ($first->State === 'reversed')
                    · <x-chip tone="neutral">Put back</x-chip> {{ \App\Support\BusinessDate::show($first->ReversedAt) }} by {{ $first->ReversedByName ?? '—' }}: “{{ $first->ReversalReason }}”
                @endif
            </p>
            @if ($canReverse && $writeId === $newest)
                <form method="POST" class="field-row"
                      action="{{ route('app.setup.employees.changes.reverse', ['branch' => $employee->BranchId, 'code' => $employee->EmployeeCode, 'write' => $writeId]) }}"
                      data-confirm="Put this back in PumpIT?"
                      data-confirm-text="{{ 'PumpIT '.$rows->pluck('PumpItTable')->unique()->implode(', ').' · '.$employee->EmployeeCode.' '.$employee->EmployeeName."\n"
                          .$rows->map(fn ($c) => $label($c).($c->AreaNo !== null ? ' '.($c->AreaDescription ?? 'area '.$c->AreaNo) : '').': '.$value($c, $c->NewValue).' → '.$value($c, $c->PriorValue))->implode("\n")
                          ."\n\nRefused if somebody has changed them since." }}"
                      data-confirm-action="Put back" data-confirm-danger>
                    @csrf
                    <x-field name="Reason" label="Why put it back" help="At least 10 characters. Kept with the reversal." />
                    <div class="form-actions"><button class="btn" type="submit">Put back</button></div>
                </form>
            @endif
        @endforeach
    @endif
</x-card>
