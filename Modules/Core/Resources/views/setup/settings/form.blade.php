{{--
    Change a setting's value, or give a site its own (T026). The rules — the
    type, the bounds, whether it may be empty, whether a site may override —
    are agora.usp_Core_SaveSetting's; this asks for the value in the shape the
    type suggests and says what the bounds are.

    Props: $setting, $branches, $row (the value being changed, or null), $mode ('edit' | 'create')
--}}
@php
    use Modules\Core\Services\SettingService;
    $group = (int) $setting->GroupBranchId;
    $isGroup = $mode === 'edit' && (int) $row->BranchId === $group;
    $title = $mode === 'create' ? 'Give a site its own value' : ($isGroup ? 'Change the group value' : 'Change '.($row->BranchName ?? 'Branch '.$row->BranchId).'\'s value');
    $current = $mode === 'edit' ? $row->Value : $setting->GroupValue;
    $numeric = in_array($setting->Type, ['int', 'decimal', 'money', 'litres', 'share'], true);
    $step = ['int' => '1', 'money' => '0.01', 'litres' => '0.001', 'share' => '0.0001', 'decimal' => '0.0001'][$setting->Type] ?? null;
    $choices = match ($setting->Type) {
        'choice' => array_combine(explode('|', $setting->Choices), explode('|', $setting->Choices)),
        'bool' => ['1' => 'Yes', '0' => 'No'],
        default => null,
    };
    if ($choices !== null && $setting->IsNullable) {
        $choices = ['' => '— none —'] + $choices;
    }
    $help = collect([
        'Group value: '.SettingService::display($setting->Type, $setting->GroupValue).'.',
        $setting->MinValue !== null ? 'From '.SettingService::display($setting->Type, $setting->MinValue).' to '.SettingService::display($setting->Type, $setting->MaxValue).'.' : null,
        $setting->Type === 'share' ? 'A share of 1: 0.75 is 75%.' : null,
        $setting->IsNullable ? 'Leave empty for none.' : null,
    ])->filter()->implode(' ');
    $action = $mode === 'create'
        ? route('app.setup.settings.overrides.store', ['key' => $setting->SettingKey])
        : route('app.setup.settings.update', ['key' => $setting->SettingKey]);
@endphp
<x-app-shell :title="$title">
    <x-page-head eyebrow="Setup · The business · System defaults and thresholds" :title="$title" :blurb="$setting->Description">
        <x-slot:actions><a class="btn" href="{{ route('app.setup.settings.show', ['key' => $setting->SettingKey]) }}">Back to setting</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif
    @if ($mode === 'create' && $branches->isEmpty())
        <x-notice tone="info" title="Every site already has its own value">Change one from the setting's page instead.</x-notice>
    @else
        <form method="POST" action="{{ $action }}"
              data-confirm="Save this value?" data-confirm-text="The change and your reason are kept in the setting's history." data-confirm-action="Save">
            @csrf
            @if ($mode === 'edit')
                @method('PUT')
                <input type="hidden" name="BranchId" value="{{ $row->BranchId }}">
                <input type="hidden" name="ExpectedVersion" value="{{ old('ExpectedVersion', $row->Version) }}">
            @endif
            <x-card :title="$setting->SettingKey" :sub="$isGroup ? 'Every site without its own value follows this' : 'Wins at this site over the group value'">
                <div class="field-row">
                    @if ($mode === 'create')
                        <x-field name="BranchId" label="Site" :choices="$branches->mapWithKeys(fn ($b) => [$b->BranchId => $b->Name])->all()" :value="old('BranchId')" />
                    @endif
                    @if ($choices !== null)
                        <x-field name="Value" label="Value" :choices="$choices" :value="old('Value', $current)" :help="$help" />
                    @else
                        <x-field name="Value" label="Value" :type="$numeric ? 'number' : 'text'" :step="$step" :min="$setting->MinValue" :max="$setting->MaxValue"
                                 :value="old('Value', $current)" :help="$help" />
                    @endif
                </div>
            </x-card>
            <x-card title="Why">
                <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least 10 characters. It is kept with the change." />
            </x-card>
            <div class="form-actions"><button class="btn-primary" type="submit">Save</button></div>
        </form>
    @endif
</x-app-shell>
