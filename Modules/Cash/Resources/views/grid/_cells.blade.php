{{--
    The ZREAD Allocation grid's cells. Everything not named here falls through
    to the shared partial and is written from its declared format.

    THE ONE COLOURED COLUMN is Decision (T036: "only the decision column
    coloured"). It is an <x-proposal> while the Z-read waits — the confidence
    chip and its rail are the colour — and a plain chip once it is allocated.
    Till, Shift and Employee are never coloured: they show what the Z-read
    carries, what it WILL carry once staged ("?", the mockup's own mark for
    "not saved yet"), or "not set". The proposal itself is not written into
    them, because a proposed name in the Employee column reads as an
    allocation to anybody skimming the list.

    Props: $column, $row, $grid
--}}
@php($state = $row->State ?? 'open')

@switch($column->key)
    @case('Decision')
        @if ($state === 'allocated')
            <x-chip tone="good">Allocated</x-chip>
            <span class="muted">{{ ($row->AllocatedBy ?? null) === 'agora' ? 'in Agora' : 'in PumpIT' }}</span>
            @if ($row->ChangedInPumpIt ?? false)
                <br><span class="muted">changed in PumpIT after Agora saved it — the PumpIT allocation is shown</span>
            @endif
        @elseif (($row->ChangedInPumpIt ?? false) && $state === 'open')
            {{-- PumpIT cleared or part-cleared an allocation Agora had written:
                 the later decision wins, and it is said, not left to be
                 discovered from a Decision that looks like any other. --}}
            <x-proposal :confidence="$row->Confidence ?? 'manual'" eyebrow="Changed in PumpIT after Agora saved it">
                {{ collect([$row->ProposedEmployeeName ?? $row->ProposedEmployeeCode, $row->ProposedShiftDescription, $row->ProposedTillDescription])->filter()->implode(' · ') }}
            </x-proposal>
        @elseif ($state === 'staged')
            <x-proposal :confidence="$row->StagedConfidence ?? 'manual'" eyebrow="Staged · Update ZREAD saves it" />
        @elseif (($row->Confidence ?? 'manual') === 'manual')
            <x-proposal confidence="manual" eyebrow="Nothing to go on" />
        @else
            <x-proposal :confidence="$row->Confidence" eyebrow="Proposed">
                {{ collect([$row->ProposedEmployeeName ?? $row->ProposedEmployeeCode, $row->ProposedShiftDescription ?? 'shift to choose', $row->ProposedTillDescription])->filter()->implode(' · ') }}
            </x-proposal>
        @endif
        @break

    @case('TillDescription')
    @case('ShiftDescription')
    @case('EmployeeName')
        @if ($state === 'open')
            <span class="muted">not set</span>
        @else
            {{ $row->{$column->key} ?? ($column->key === 'EmployeeName' ? $row->EmployeeCode : \App\Support\Format::NOTHING) }}@if ($state === 'staged') ?@endif
        @endif
        @break

    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
