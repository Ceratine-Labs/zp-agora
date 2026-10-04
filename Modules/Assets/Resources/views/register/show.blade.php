{{--
    One asset, whole (T057).

    In the order somebody asks: what is it worth, what is it and where, what
    has happened to it, and how it depreciates. The actions are the page
    head's — edit, move, raise a job card, delete — each rendered only for
    somebody who holds its permission, and each refused by its procedure for
    anybody who gets past that.

    Delete appears only while the asset has no history: after its first
    movement or job card it leaves the register by a disposal or a write-off,
    which is a movement (feature-rules §4).
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;

    $date = fn ($d) => $d === null ? null : Carbon::parse((string) $d)->format('j M Y');
    $gone = in_array($asset->StatusCode, ['DISPOSED', 'WRITTEN_OFF'], true);
    $away = ! $gone && (int) $asset->LocatedBranchId !== (int) $asset->BranchId;
    $noRate = $asset->DepreciationState === 'NO_RATE';
    $latest = $movements->firstWhere('IsLatest', 1);
    $repairKey = $asset->BranchId.':'.$asset->AssetId;
@endphp

<x-app-shell :title="$asset->AssetNo">
    <x-page-head eyebrow="Asset register"
                 :title="$asset->AssetNo.' · '.($asset->AssetTypeDescription ?: $asset->AssetTypeCode)"
                 :blurb="trim($asset->Make.' '.($asset->Model ?? '')).($asset->SerialNo ? ' · serial '.$asset->SerialNo : '').' · belongs to '.$asset->BranchName">
        <x-slot:info>
            <p>Depreciation is straight-line and monthly, from the month the asset was bought, and a month is
               charged at its month end.@if ($provisional) The period for its group is provisional.@endif</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.register') }}">Back to the register</a>
            @if (! $gone)
                @can('assets.movement.save')
                    <button type="button" class="btn" data-modal-open="move-asset">Record a movement</button>
                @endcan
                @can('assets.repair.save')
                    <button type="button" class="btn" data-modal-open="raise-repair">Raise a job card</button>
                @endcan
            @endif
            @can('assets.register.edit')
                <button type="button" class="btn-primary" data-modal-open="edit-asset">Edit</button>
            @endcan
            @if (! $asset->HasHistory)
                @can('assets.register.delete')
                    <button type="button" class="btn-ghost" data-modal-open="delete-asset">Delete</button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That was not sent">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    @if ($gone)
        <x-notice tone="info" title="{{ $asset->Status }} on {{ $date($asset->DisposedOn) }}">
            Off the register and no longer depreciated.
            @if ($asset->DisposalProceeds !== null)
                It fetched {{ Format::r($asset->DisposalProceeds) }}.
            @endif
        </x-notice>
    @elseif ($away)
        <x-notice tone="warn" title="Away from its site">
            It belongs to {{ $asset->BranchName }} and is standing at {{ $asset->LocatedAt }}: on loan, or back
            home without the return being recorded.
        </x-notice>
    @endif

    <x-kpi-strip>
        <x-kpi label="Cost" :value="Format::r($asset->Cost)" :note="'Bought '.$date($asset->DatePurchased)" />
        <x-kpi label="Depreciation to date" :value="$noRate ? Format::NOTHING : Format::r($asset->Accumulated)"
               :note="$noRate ? 'No period for this group' : Format::n($asset->MonthsCharged).' of '.Format::n($asset->LifeMonths).' months'" />
        <x-kpi label="NBV" :value="$noRate ? Format::NOTHING : Format::r($asset->Nbv)"
               :note="'As at '.$date($asset->AsAtMonthEnd).($provisional ? ' · provisional rate' : '')"
               :tone="$provisional ? 'warn' : 'neutral'" />
        <x-kpi label="Charge for {{ $date($asset->AsAtMonthEnd) }}" :value="$noRate ? Format::NOTHING : Format::r($asset->ChargeForMonth)"
               :note="$noRate ? null : Format::r($asset->MonthlyCharge).' a month, straight-line'" />
    </x-kpi-strip>

    <x-card title="What it is and where">
        <x-facts>
            <x-fact label="Asset no" :value="$asset->AssetNo">
                Group {{ $asset->AssetGroupCode }}, a sequence per site and group, and the site it belongs to.
            </x-fact>
            <x-fact label="Group and type" :value="$asset->AssetGroupDescription">
                {{ $asset->AssetTypeCode }} {{ $asset->AssetTypeDescription }} — the customer's own list.
            </x-fact>
            <x-fact label="Status" :value="$asset->Status" :tone="$asset->StatusCode === 'AT_REPAIR' ? 'warn' : null">
                Changed only by a movement.
            </x-fact>
            <x-fact label="Condition" :value="$asset->Condition">
                As last recorded on the register.
            </x-fact>
            <x-fact label="Belongs to" :value="$asset->BranchName">
                The register it is on. It does not change when the asset is moved.
            </x-fact>
            <x-fact label="Standing at" :value="$asset->LocatedAt" :tone="$away ? 'warn' : null">
                {{ $away ? 'Not its own site.' : 'Its own site.' }}
            </x-fact>
            <x-fact label="Owner" :value="$asset->AssetOwner ?? $asset->BranchName">
                {{ $asset->AssetOwner ? 'Owned by somebody other than the site.' : 'The site itself.' }}
            </x-fact>
            <x-fact label="Supplier" :value="$asset->SupplierName ?? $asset->SupplierCode">
                {{ $asset->SupplierCode ?? 'Not recorded.' }}
            </x-fact>
            <x-fact label="Allocated" :value="$date($asset->DateAllocated)">
                When the site took it into use.
            </x-fact>
            <x-fact label="Last changed" :value="$date($asset->UpdatedAt ?? $asset->CreatedAt)">
                {{ $asset->UpdatedByName ?? $asset->CreatedByName ?? 'Unknown' }}{{ $asset->Reason ? ' — '.$asset->Reason : '' }}
            </x-fact>
        </x-facts>
    </x-card>

    <x-card title="Movements">
        <x-slot:info>
            <p>Newest first. A reversed movement stays, marked, with who took it back and why.</p>
        </x-slot:info>
        <x-table procedure="agora.usp_Assets_GetAsset" :count="$movements->count()" dense
                 empty="Nothing has happened to this asset since it was registered.">
            <x-slot:head>
                <tr>
                    <th>Movement</th><th>Date</th><th>Type</th><th>From</th><th>To</th>
                    <th class="num">Proceeds</th><th>Reason</th><th>By</th><th></th>
                </tr>
            </x-slot:head>
            @foreach ($movements as $m)
                <tr>
                    <td class="mono">{{ $m->MovementRef }}</td>
                    <td class="mono">{{ substr((string) $m->MovementDate, 0, 10) }}</td>
                    <td>{{ $m->MovementTypeDescription }}</td>
                    <td>{{ $m->FromSite }}</td>
                    <td>{{ $m->ToSite }}</td>
                    <td class="num">{{ Format::r($m->Proceeds) }}</td>
                    <td>{{ $m->Reason }}</td>
                    <td>{{ $m->CapturedBy }}</td>
                    <td>
                        @if ($m->ReversedAt !== null)
                            <x-chip tone="neutral" :dot="false">Reversed</x-chip>
                            <span class="muted">{{ $m->ReverseReason }}</span>
                        @elseif ($m->IsLatest)
                            @can('assets.movement.edit')
                                <button type="button" class="btn-ghost" data-modal-open="reverse-movement">Reverse</button>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="Job cards">
        <x-slot:info>
            <p>A comeback is a second call on this asset within 30 days of the one before.</p>
        </x-slot:info>
        <x-table procedure="agora.usp_Assets_GetAsset" :count="$repairs->count()" dense
                 empty="No repair has been called in on this asset.">
            <x-slot:head>
                <tr>
                    <th>Job card</th><th>Called</th><th>Site</th><th>Supplier</th><th>Problem</th>
                    <th>Request</th><th class="num">Cost</th><th>Back / done</th><th></th>
                </tr>
            </x-slot:head>
            @foreach ($repairs as $r)
                <tr>
                    <td class="mono"><a href="{{ route('app.assets.repairs.show', ['branch' => $asset->BranchId, 'repair' => $r->RepairId]) }}">{{ $r->JobCardNo }}</a></td>
                    <td class="mono">{{ substr((string) $r->CallDate, 0, 10) }}</td>
                    <td>{{ $r->SiteName }}</td>
                    <td>{{ $r->SupplierName ?? $r->SupplierCode }}</td>
                    <td>{{ $r->Problem }}</td>
                    <td>{{ $r->TransactionNo ?? Format::NOTHING }}</td>
                    <td class="num">{{ Format::r($r->RepairCost) }}</td>
                    <td class="mono">{{ $r->DateActualReturn ? substr((string) $r->DateActualReturn, 0, 10) : Format::NOTHING }}</td>
                    <td>
                        @if ($r->IsCancelled)
                            <x-chip tone="neutral" :dot="false">Cancelled</x-chip>
                        @elseif ($r->IsComeback)
                            <x-chip tone="warn">Comeback · {{ $r->DaysSincePrevious }} days after {{ $r->PrevJobCardNo }}</x-chip>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    {{-- The method that was this card's sub is behind the page's info button:
         a collapsible card cannot carry one in its head. --}}
    <x-card title="Depreciation schedule"
            collapsible remember="assets.asset.schedule" :open="false">
        @if ($noRate)
            <x-empty-state title="Not depreciated"
                           text="There is no depreciation period for group {{ $asset->AssetGroupCode }}, so there is no schedule. The periods are on Groups and types." />
        @else
            <x-table procedure="agora.usp_Assets_Depreciate" :count="$schedule->count()" dense>
                <x-slot:head>
                    <tr><th class="num">Month</th><th>Month end</th><th class="num">Charge</th><th class="num">Accumulated</th><th class="num">NBV</th></tr>
                </x-slot:head>
                @foreach ($schedule as $s)
                    <tr>
                        <td class="num">{{ $s->MonthNo }}</td>
                        <td class="mono">{{ substr((string) $s->MonthEnd, 0, 10) }}</td>
                        <td class="num">{{ Format::r($s->Charge) }}</td>
                        <td class="num">{{ Format::r($s->Accumulated) }}</td>
                        <td class="num">{{ Format::r($s->Nbv) }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    {{-- ---- the forms, each behind its permission ------------------------ --}}

    @can('assets.register.edit')
        @include('assets::register.partials.form', [
            'modalId' => 'edit-asset',
            'modalTitle' => 'Change '.$asset->AssetNo,
            'action' => route('app.assets.register.update', ['branch' => $asset->BranchId, 'asset' => $asset->AssetId]),
            'method' => 'PUT',
            'asset' => $asset,
            'pinned' => null,
        ])
    @endcan

    @if (! $gone)
        @can('assets.movement.save')
            @include('assets::movement.partials.form', [
                'modalId' => 'move-asset',
                'assetKey' => $repairKey,
                'assetLabel' => $asset->AssetNo.' · at '.$asset->LocatedAt.' · '.$asset->Status,
                'movementTypes' => $masters['movementTypes'] ?? collect(),
            ])
        @endcan

        @can('assets.repair.save')
            @include('assets::repairs.partials.form', [
                'modalId' => 'raise-repair',
                'modalTitle' => 'Raise a job card on '.$asset->AssetNo,
                'action' => route('app.assets.repairs.store'),
                'method' => 'POST',
                'repair' => null,
                'assetKey' => $repairKey,
                'assets' => collect(),
            ])
        @endcan
    @endif

    @if ($latest !== null)
        @can('assets.movement.edit')
            <x-modal id="reverse-movement" title="Reverse {{ $latest->MovementRef }}">
                <form method="POST" action="{{ route('app.assets.movement.reverse', ['branch' => $asset->BranchId, 'asset' => $asset->AssetId, 'movement' => $latest->MovementId]) }}">
                    @csrf
                    <p class="field-help" style="margin-top:0">
                        {{ $latest->MovementTypeDescription }} on {{ $date($latest->MovementDate) }}, {{ $latest->FromSite }} → {{ $latest->ToSite }}.
                    </p>
                    <x-field name="reason" label="Why" help="Required. Kept on the movement."
                             info="Reversing puts the asset back exactly where and as it was before this movement. The movement stays on the record, marked reversed." />
                    <div class="btn-row">
                        <button type="submit" class="btn-primary">Reverse it</button>
                    </div>
                </form>
            </x-modal>
        @endcan
    @endif

    @if (! $asset->HasHistory)
        @can('assets.register.delete')
            <x-modal id="delete-asset" title="Delete {{ $asset->AssetNo }}">
                <form method="POST" action="{{ route('app.assets.register.destroy', ['branch' => $asset->BranchId, 'asset' => $asset->AssetId]) }}"
                      data-confirm="Delete {{ $asset->AssetNo }} from the register?"
                      data-confirm-text="Only for an asset registered in error. Its number is never issued again."
                      data-confirm-action="Delete it" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <x-field name="reason" label="Why" help="Required. Kept against the row."
                             info="For an asset registered in error. One that really left the site is sold, traded in or written off instead — that is a movement, and it keeps the history." />
                    <div class="btn-row">
                        <button type="submit" class="btn-primary">Delete it</button>
                    </div>
                </form>
            </x-modal>
        @endcan
    @endif
</x-app-shell>
