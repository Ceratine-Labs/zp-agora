{{--
    The asset register (T057).

    The grid is the page. Above it, only what changes how its figures read:
    the depreciation periods are PROVISIONAL (Ryan, 27 Sep 2026 — SARS
    wear-and-tear per group until ZP confirm their own), and the date the
    register is valued at. Below it, the same register added up by group —
    the monthly charge an accountant asks for.

    The register is Agora's own table. Nothing here writes the customer's
    databases; the groups, types and owners are theirs, read-only.

    The explanation — what the register shows, how the as-at date is read, and
    the provisional basis — sits behind the info button beside the title (4 Oct
    2026). That the rates ARE provisional stays on the page as the blurb: it
    changes how every figure underneath reads.
--}}
@php
    use App\Support\Format;
@endphp
<x-app-shell title="Asset register">
    <x-page-head eyebrow="People and assets" title="Asset register"
                 :blurb="$provisional ? 'Provisional rates: depreciation and NBV use stand-in periods until ZP confirm their own.' : null">
        <x-slot:info>
            <p>Every asset by site and group: what it cost, what it has depreciated by, what it is worth, and
               whether it is where its own site thinks it is.</p>
            <p>A month is charged at its month end, so the register is valued to the last month end on or before
               the <strong>Valued as at</strong> date. The totals by group cover the sites the grid is showing.</p>
            @if ($provisional)
                <p><strong>The periods are provisional.</strong> {{ $basis }} Changing one restates every asset in
                   its group; nothing is posted. Each group's period, and what it covers badly, is on
                   <a href="{{ route('app.assets.masters') }}">Groups and types</a>.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.movement') }}">Movements</a>
            <a class="btn" href="{{ route('app.assets.repairs') }}">Repairs</a>
            <a class="btn" href="{{ route('app.assets.masters') }}">Groups and types</a>
            @can('assets.register.save')
                <button type="button" class="btn-primary" data-modal-open="register-asset">Register an asset</button>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That asset was not registered">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    <form method="GET" action="{{ route('app.assets.register') }}">
        {{-- The grid's own scope travels through, so valuing at another date
             does not throw away the sites and filters being looked at. --}}
        @foreach (request()->query() as $name => $value)
            @continue($name === 'as_at' || is_array($value))
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <x-params legend="Valued as at">
            <x-param name="as_at" label="Valued as at" type="date" :value="$asAt" />
        </x-params>
        <div class="form-actions">
            <button type="submit" class="btn">Value the register</button>
        </div>
    </form>

    <x-data-grid :grid="$grid" :branches="$gridBranches" surface />

    <x-card title="Depreciation by group"
            sub="As at the last month end on or before {{ \Illuminate\Support\Carbon::parse($asAt)->format('j M Y') }}"
            collapsible remember="assets.register.bygroup">
        <x-table procedure="agora.usp_Assets_Depreciate" :count="$byGroup->count()" dense
                 empty="No assets on the register for these sites yet.">
            <x-slot:head>
                <tr>
                    <th>Group</th>
                    <th class="num">Assets</th>
                    <th class="num">Life (months)</th>
                    <th class="num">Cost</th>
                    <th class="num">Depreciation</th>
                    <th class="num">NBV</th>
                    <th class="num">Month's charge</th>
                </tr>
            </x-slot:head>
            @foreach ($byGroup as $g)
                <tr>
                    <td><span class="mono">{{ $g->AssetGroupCode }}</span> {{ $g->AssetGroupDescription }}</td>
                    <td class="num">{{ Format::n($g->OnRegister) }}</td>
                    <td class="num">{{ $g->LifeMonths === null ? Format::NOTHING : Format::n($g->LifeMonths) }}</td>
                    <td class="num">{{ Format::r($g->Cost) }}</td>
                    <td class="num">{{ Format::r($g->Accumulated) }}</td>
                    <td class="num">{{ Format::r($g->Nbv) }}</td>
                    <td class="num">{{ Format::r($g->ChargeForMonth) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @can('assets.register.save')
        @include('assets::register.partials.form', [
            'modalId' => 'register-asset',
            'modalTitle' => 'Register an asset',
            'action' => route('app.assets.register.store'),
            'method' => 'POST',
            'asset' => null,
        ])
    @endcan
</x-app-shell>
