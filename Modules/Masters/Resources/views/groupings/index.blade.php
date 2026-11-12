<x-app-shell title="Regions, brands and formats">
    <x-page-head eyebrow="Setup · The business" title="Regions, brands and formats" blurb="How branches are grouped in every head-office list and report.">
        <x-slot:info>
            <p>Derived from PumpIT's SS_Region, SS_Brand and SS_Class when the branches were migrated, and Agora's own since. A change here is recorded with its reason; PumpIT is not written.</p>
            <p>One still carried by an active branch cannot be switched off — move the branches first. Nothing is deleted.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ route('app.setup.branches.index') }}">Branches</a></x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    @foreach ($kinds as $kind)
        @php($rows = $groupings->get($kind, collect()))
        <x-card :title="$kind === 'Format' ? 'Formats' : $kind.'s'" :sub="$rows->count().' in all'">
            <x-table :layout-key="'Masters.groupings.'.$kind" tools procedure="agora.usp_Masters_GetBranchGroupings" :count="$rows->count()">
                <x-slot:head><tr><th>Name</th><th>Branches</th><th>Carried by</th><th>In use</th><th>From PumpIT</th><th></th></tr></x-slot:head>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->Name }}</td>
                        <td class="num">{{ $row->BranchCount }}</td>
                        <td>{{ $row->Branches ?? '—' }}</td>
                        <td>{{ $row->IsActive ? 'Yes' : 'Switched off' }}</td>
                        <td>{{ $row->LegacyId !== null ? 'Id '.$row->LegacyId : 'Added in Agora' }}</td>
                        <td>
                            @can('masters.groupings.edit')
                                <details>
                                    <summary>Change</summary>
                                    <form method="POST" action="{{ route('app.setup.branch-groupings.update', ['kind' => $kind, 'id' => $row->Id]) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="ExpectedVersion" value="{{ $row->Version }}">
                                        <x-field :name="'Name'" label="Name" :value="$row->Name" />
                                        <x-field name="IsActive" type="bool" label="In use" placeholder="In use" :value="(bool) $row->IsActive" />
                                        <x-field name="Reason" label="Reason" help="At least 10 characters." />
                                        <button class="btn sm" type="submit">Save</button>
                                    </form>
                                </details>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-table>
            @can('masters.groupings.save')
                <details>
                    <summary>Add a {{ strtolower($kind) }}</summary>
                    <form method="POST" action="{{ route('app.setup.branch-groupings.store', ['kind' => $kind]) }}">
                        @csrf
                        <x-field name="Name" label="Name" />
                        <input type="hidden" name="IsActive" value="1">
                        <x-field name="Reason" label="Reason" help="At least 10 characters." />
                        <button class="btn sm" type="submit">Add {{ strtolower($kind) }}</button>
                    </form>
                </details>
            @endcan
        </x-card>
    @endforeach
</x-app-shell>
