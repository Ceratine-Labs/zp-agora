{{--
    Editing one reference master — a write back to PumpIT's own table
    (CLAUDE.md rule 1; Ryan, 12 Nov 2026: "agora is replacing pumpit, always
    write back to pumpit with confirmation"). The fields are the definition's
    editableFields(); the confirmation names the table, the row and each
    column old → new before anything is sent.
--}}
@php
    $title = $definition->recordTitle($row);
    $site = $definition->perBranch() ? ($row->BranchName ?? 'branch '.$row->BranchId) : 'the group';
    $show = $definition->rowUrl($row);
    $what = 'PumpIT '.$definition->legacySource().' · '.$title.' at '.$site;
    // 19.0000 reads as 19 in the box and in the confirmation; 10 stays 10.
    $plain = fn ($v) => is_numeric($v) && str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) ($v ?? '');
@endphp
<x-app-shell :title="'Edit '.$title">
    <x-page-head :eyebrow="$definition->eyebrow().' · '.$definition->title()" :title="'Edit '.$title" :blurb="$definition->noun().' · '.$site">
        <x-slot:info>
            <p>These changes are written to PumpIT's {{ $definition->legacySource() }}, because PumpIT still trades on it. Each value is recorded first, with your reason, so the change can be put back from this record's page.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ $show }}">Back to {{ strtolower($definition->noun()) }}</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    <form method="POST" action="{{ route('app.setup.'.$definition->slug().'.update', ['branch' => $row->BranchId]) }}"
          data-confirm="Write this to PumpIT?" data-confirm-action="Save to PumpIT"
          data-confirm-text="{{ $what }}. Each change and your reason are recorded, and can be put back."
          data-diff="{{ $what }}" data-diff-note="Each change and your reason are recorded, and can be put back.">
        @csrf @method('PUT')
        <input type="hidden" name="key" value="{{ $row->RowKey }}">
        <x-card :title="$title" :sub="'Written to '.$definition->legacySource()">
            <div class="field-row">
                @foreach ($definition->editableFields() as $name => $field)
                    @php($current = $row->{$name} ?? null)
                    <input type="hidden" name="Expected{{ $name }}" value="{{ ($field['type'] ?? 'text') === 'bool' ? (int) $current : $current }}">
                    @if (($field['type'] ?? 'text') === 'bool')
                        <x-field :name="$name" type="bool" :label="$field['label']" :placeholder="$field['label']" :help="$field['help'] ?? null"
                                 :data-diff-field="$field['column']" :data-diff-old="(int) $current" data-diff-on="Yes" data-diff-off="No"
                                 :value="(bool) old($name, $current)" />
                    @else
                        <x-field :name="$name" :type="($field['type'] ?? 'text') === 'decimal' ? 'number' : 'text'" :label="$field['label']"
                                 :min="$field['min'] ?? null" :max="$field['max'] ?? null" :step="$field['step'] ?? null" :help="$field['help'] ?? null"
                                 :data-diff-field="$field['column']" :data-diff-old="$plain($current)" :value="old($name, $plain($current))" />
                    @endif
                @endforeach
            </div>
            <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least 10 characters. Kept with every value you change." />
            <div class="form-actions"><button class="btn-primary" type="submit">Save to PumpIT</button></div>
        </x-card>
    </form>
</x-app-shell>
