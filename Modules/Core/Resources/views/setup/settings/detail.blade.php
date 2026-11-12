{{--
    One setting (T026): what it is, the group value, the sites with their own,
    and the history. The page body and the grid's expanded row are this same
    partial.

    Props: $setting, $overrides, $history, $branches (from usp_Core_GetSetting)
--}}
@php
    use Modules\Core\Services\SettingService;
    $show = route('app.setup.settings.show', ['key' => $setting->SettingKey]);
    $readOnly = (bool) $setting->IsReadOnly;
    $bounds = $setting->MinValue !== null || $setting->MaxValue !== null
        ? SettingService::display($setting->Type, $setting->MinValue).' to '.SettingService::display($setting->Type, $setting->MaxValue) : null;
    $fields = ['Value' => 'Value changed', 'Override' => 'Site value'];
@endphp
<x-card :title="$setting->SettingKey" :sub="$readOnly ? 'A copy of PumpIT\'s '.$setting->LegacySource.' — read-only' : ($setting->AllowsBranch ? 'A site may carry its own value' : 'One value for every site')">
    <x-slot:actions>
        <a class="btn sm" href="{{ $show }}">View</a>
        @if (! $readOnly && $setting->HasGroupRow)
            @can('setup.settings.edit')
                <a class="btn sm" href="{{ route('app.setup.settings.edit', ['key' => $setting->SettingKey]) }}">Change group value</a>
            @endcan
        @endif
        @if (! $readOnly && $setting->AllowsBranch)
            @can('setup.settings.save')
                <a class="btn sm" href="{{ route('app.setup.settings.overrides.create', ['key' => $setting->SettingKey]) }}">Give a site its own value</a>
            @endcan
        @endif
    </x-slot:actions>
    <x-facts>
        <x-fact label="Group value" :value="$setting->HasGroupRow ? SettingService::display($setting->Type, $setting->GroupValue) : '—'">
            {{ $setting->HasGroupRow ? 'Every site without its own value follows this.' : 'PumpIT kept this per branch only; there is no group value.' }}
        </x-fact>
        <x-fact label="Type" :value="$setting->Type">
            @if ($setting->Type === 'choice') One of {{ str_replace('|', ', ', $setting->Choices) }}. @endif
            @if ($setting->Type === 'share') A share of 1 — 0.75 is 75%. @endif
            @if ($setting->IsNullable) May be left empty. @endif
        </x-fact>
        <x-fact label="Allowed" :value="$bounds ?? '—'" />
        <x-fact label="Read by" :value="$setting->UsedBy ?? 'Nothing yet'">{{ $setting->UsedBy ? '' : 'Seeded so the figure is here when the screen that needs it is built.' }}</x-fact>
        <x-fact label="Sites with their own" :value="\App\Support\Format::n($overrides->count())" />
        <x-fact label="Last changed" :value="$setting->UpdatedAt ? \App\Support\BusinessDate::show($setting->UpdatedAt) : '—'">{{ $setting->UpdatedByName }}</x-fact>
    </x-facts>
</x-card>

<x-card title="Sites with their own value" :sub="$overrides->isEmpty() ? 'None — every site follows the group value' : $overrides->count().' sites'">
    @if ($overrides->isEmpty())
        <x-empty-state :text="$setting->AllowsBranch ? 'No site has its own value.' : 'This setting has one value for every site.'" />
    @else
        <x-table layout-key="Core.settings.overrides" tools :count="$overrides->count()" procedure="agora.usp_Core_GetSetting">
            <x-slot:head><tr><th>Branch</th><th class="num">Value</th><th>Changed</th><th>By</th><th></th></tr></x-slot:head>
            @foreach ($overrides as $override)
                <tr>
                    <td><a href="{{ route('app.setup.branches.show', ['branch' => $override->BranchId]) }}">{{ $override->BranchName ?? 'Branch '.$override->BranchId }}</a></td>
                    <td class="num">{{ SettingService::display($setting->Type, $override->Value) }}</td>
                    <td>{{ \App\Support\BusinessDate::show($override->UpdatedAt ?? $override->CreatedAt) }}</td>
                    <td>{{ $override->UpdatedByName }}</td>
                    <td>
                        @if (! $readOnly)
                            @can('setup.settings.edit')
                                <a class="btn sm" href="{{ route('app.setup.settings.edit', ['key' => $setting->SettingKey, 'branch' => $override->BranchId]) }}">Change</a>
                            @endcan
                            @can('setup.settings.delete')
                                <form method="POST" class="inline-reason" action="{{ route('app.setup.settings.overrides.destroy', ['key' => $setting->SettingKey, 'branch' => $override->BranchId]) }}"
                                      data-confirm="Remove this site's own value?" data-confirm-text="The site follows the group value again. Your reason is kept in the history." data-confirm-action="Remove" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="ExpectedVersion" value="{{ $override->Version }}">
                                    <input type="text" name="Reason" minlength="10" maxlength="400" required placeholder="Why (10+ characters)" aria-label="Reason for removing {{ $override->BranchName }}'s value">
                                    <button class="btn sm" type="submit">Remove</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>

<x-card title="History" sub="Every change to this setting, newest first">
    @if ($history->isEmpty())
        <x-empty-state text="Nothing recorded yet — the value is the one it was seeded with." />
    @else
        <x-table layout-key="Core.settings.history" tools :count="$history->count()" procedure="agora.usp_Core_GetSetting">
            <x-slot:head><tr><th>When</th><th>Who</th><th>Where</th><th>What</th><th class="num">Before</th><th class="num">After</th><th>Why</th></tr></x-slot:head>
            @foreach ($history as $change)
                <tr>
                    <td>{{ \App\Support\BusinessDate::show($change->CreatedAt) }}</td>
                    <td>{{ $change->ChangedByName ?? ($change->Source === 'migrate' ? 'Migration' : $change->CreatedBy) }}</td>
                    <td>{{ (int) $change->BranchId === (int) $setting->GroupBranchId ? 'Group' : ($change->BranchName ?? 'Branch '.$change->BranchId) }}</td>
                    <td>{{ $fields[$change->Field] ?? $change->Field }}</td>
                    <td class="num">{{ SettingService::display($setting->Type, $change->PriorValue) }}</td>
                    <td class="num">{{ SettingService::display($setting->Type, $change->NewValue) }}</td>
                    <td>{{ $change->Reason }}</td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>
