{{-- What Agora has written to PumpIT for this supplier or customer, a press
     at a time, each with Put back where the caller may and it is still in
     force. Expects $changes (from PartnerWriteService::changes), $reverseRoute
     and $routeParams. --}}
@php
    $value = function ($c, $v) {
        if (in_array($c->Field, ['isActive', 'IsCash', 'IsStock'], true)) {
            return $v === '1' ? ($c->Field === 'isActive' ? 'Active' : 'Yes') : ($v === '0' ? ($c->Field === 'isActive' ? 'Inactive' : 'No') : ($v ?? '—'));
        }
        return $v === null || $v === '' ? '—' : $v;
    };
    $label = fn ($c) => [
        'SupplierName' => 'Name', 'ContactPerson' => 'Contact', 'Department' => 'Department', 'isActive' => 'Status',
        'IsCash' => 'Paid in cash', 'IsStock' => 'Stock supplier', 'CustomerName' => 'Name', 'CustomerContact' => 'Contact',
        'JobTitle' => 'Job title', 'TelNoA' => 'Telephone', 'TelNoB' => 'Telephone 2', 'Row' => 'Added',
    ][$c->Field] ?? $c->Field;
    // Put back is offered on the NEWEST edit still in force only: presses are
    // undone in the order they were made, and an older one would be MOVED.
    $newest = $changes->filter(fn ($rows) => $rows->first()->State === 'active' && ! str_ends_with((string) $rows->first()->Verb, '-add'))->keys()->first();
@endphp
<x-card title="Changes written to PumpIT" :sub="$changes->isEmpty() ? 'None yet' : $changes->count().' press(es), newest first'">
    @if ($changes->isEmpty())
        <x-empty-state text="Agora has not changed this in PumpIT." />
    @else
        @foreach ($changes as $writeId => $rows)
            @php($first = $rows->first())
            <x-table layout-key="Partners.changes" procedure="agora.usp_Partners_GetChanges" :count="$rows->count()" dense>
                <x-slot:head><tr><th>Site</th><th>What</th><th>Before</th><th>After</th></tr></x-slot:head>
                @foreach ($rows as $c)
                    <tr>
                        <td>{{ $c->BranchName ?? 'Branch '.$c->BranchId }}</td>
                        <td>{{ $label($c) }}</td>
                        <td>{{ $value($c, $c->PriorValue) }}</td>
                        <td>{{ $value($c, $c->NewValue) }}</td>
                    </tr>
                @endforeach
            </x-table>
            <p class="muted">
                {{ \App\Support\BusinessDate::show($first->CreatedAt) }} · {{ $first->ChangedByName ?? $first->CreatedBy ?? '—' }} · “{{ $first->Reason }}”
                @if ($first->State === 'reversed')
                    · <x-chip tone="neutral">Put back</x-chip> {{ \App\Support\BusinessDate::show($first->ReversedAt) }} by {{ $first->ReversedByName ?? '—' }}: “{{ $first->ReversalReason }}”
                @endif
            </p>
            @if ($canReverse && $writeId === $newest)
                <form method="POST" class="field-row" action="{{ route($reverseRoute, $routeParams + ['write' => $writeId]) }}"
                      data-confirm="Put this back in PumpIT?"
                      data-confirm-text="{{ 'PumpIT '.$first->PumpItTable.' · '.$first->PartnerCode."\n"
                          .$rows->map(fn ($c) => ($c->BranchName ?? $c->BranchId).' · '.$label($c).': '.$value($c, $c->NewValue).' → '.$value($c, $c->PriorValue))->implode("\n")
                          ."\n\nRefused if somebody has changed them since." }}"
                      data-confirm-action="Put back" data-confirm-danger>
                    @csrf
                    <x-field name="Reason" label="Why put it back" help="At least {{ (int) config('partners.reason_min', 10) }} characters. Kept with the reversal." />
                    <div class="form-actions"><button class="btn" type="submit">Put back</button></div>
                </form>
            @endif
        @endforeach
    @endif
</x-card>
