<x-app-shell title="Email templates">
    <x-page-head eyebrow="Setup · Email" title="Email templates">
        <x-slot:info><p>Group defaults apply across the business. An active branch override replaces the default only at that branch. Disabling an override returns that branch to the default.</p></x-slot:info>
    </x-page-head>
    <x-card title="Find a template" collapsible :remember="'email.templates.scope:'.auth()->id()">
        <form method="GET" action="{{ route('app.setup.email-templates.index') }}">
            <div class="field-row">
                <x-field name="module" label="Module" :choices="['' => 'All modules'] + array_combine($modules, array_map('ucfirst', $modules))" :value="$module" />
                <button class="btn" type="submit">Filter templates</button>
            </div>
        </form>
    </x-card>
    <x-card title="Templates" flush>
        <x-table :count="$templates->count()" empty="No templates in this module.">
            <x-slot:head><tr><th>Code</th><th>Module</th><th>Scope</th><th>Status</th><th>Version</th><th>Open</th></tr></x-slot:head>
            @foreach ($templates as $template)
                <tr>
                    <td><code>{{ $template->Code }}</code></td><td>{{ ucfirst($template->ModuleTag) }}</td>
                    <td>{{ $names[$template->BranchId] }}</td>
                    <td><x-chip :tone="$template->IsActive ? 'good' : 'neutral'">{{ $template->IsActive ? 'Active' : 'Inactive' }}</x-chip></td>
                    <td>{{ $template->Version }}</td>
                    <td><a class="btn sm" href="{{ route('app.setup.email-templates.edit', ['branch' => $template->BranchId, 'code' => $template->Code]) }}">Open {{ $template->Code }}</a></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
    @can('email.templates.edit')
        <x-card title="Create or open a branch override">
            <form method="GET" action="{{ route('app.setup.email-templates.open') }}">
                <div class="field-row">
                    <div class="field"><label for="template-code">Template code</label><select id="template-code" name="code" data-select>
                        @foreach ($definitions as $definition)<option value="{{ $definition->code }}">{{ $definition->code }}</option>@endforeach
                    </select></div>
                    <div class="field"><label for="template-scope">Template scope</label><select id="template-scope" name="scope" data-select>
                        @foreach ($scopes as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select></div>
                </div>
                <button class="btn-primary" type="submit">Open override editor</button>
            </form>
        </x-card>
    @endcan
</x-app-shell>
