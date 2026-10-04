{{--
    The asset masters, read-only (T057).

    The four lists are the customer's own PumpIT tables — ASSET_AssetGroup,
    ASSET_AssetType, ASSET_AssetOwner, ASSET_AssetMovementType — and Agora never
    writes them (Ryan, 27 Sep 2026, PM decision 01a0e309). So there is no edit
    here: a new group or type is added in PumpIT and appears on the next load.

    The one thing on this page that is Agora's is the depreciation period
    beside each group, and it is PROVISIONAL: SARS wear-and-tear, one period
    per group, from Modules/Assets/Config/config.php until ZP confirm their
    own policy.

    Each tab is a link, so a tab is a place you can send somebody. Every table
    arrived whole — 21 groups, 431 types — so it carries the browser-side sort
    and filter (`tools`); nothing here is paged by the server.

    What the lists are, the provisional basis and each tab's note sit behind
    the info button beside the title (4 Oct 2026). That the periods ARE
    provisional stays on the groups tab as the blurb. The owners and movement
    notes were card subs on untitled cards, which <x-card> never rendered.
--}}
@php
    use App\Support\Format;

    $counts = [
        'groups' => $masters['groups']->count(),
        'types' => $masters['types']->count(),
        'owners' => $masters['owners']->count(),
        'movements' => $masters['movementTypes']->count(),
    ];
    $items = collect($tabs)->map(fn ($label, $key) => [
        'key' => $key,
        'label' => $label,
        'href' => route('app.assets.masters', ['tab' => $key]),
        'count' => $counts[$key],
    ])->values()->all();
@endphp

<x-app-shell title="Asset groups and types">
    <x-page-head eyebrow="People and assets" title="Asset groups and types"
                 :blurb="$tab === 'groups' && $provisional ? 'Provisional rates: one stand-in period per group until ZP confirm their own.' : null">
        <x-slot:info>
            <p>The customer's own asset lists, read from PumpIT — a new group or type is added there. The
               depreciation period beside each group is Agora's.</p>
            @if ($tab === 'groups' && $provisional)
                <p><strong>The periods are provisional.</strong> {{ $basis }}</p>
                <p>Most groups mix kinds of asset, so the last column says what the period covers badly. Changing a
                   period restates every asset in the group; nothing is posted.</p>
            @elseif ($tab === 'owners')
                <p>Owners are branches and third parties — an oil company's pump, a brewery's fridge.</p>
            @elseif ($tab === 'movements')
                <p>Five types are the customer's. The return from repair is Agora's own: the legacy list has a way
                   out to repair and no way back.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.register') }}">Back to the register</a>
        </x-slot:actions>
    </x-page-head>

    <x-tabs :items="$items" :active="$tab" label="Asset masters" />

    @if ($tab === 'groups')
        <x-card flush>
            <x-table procedure="agora.usp_Assets_Masters" :count="$counts['groups']" tools dense>
                <x-slot:head>
                    <tr>
                        <th>Group</th>
                        <th>Description</th>
                        <th class="num">Types</th>
                        <th class="num">Assets</th>
                        <th>SARS class</th>
                        <th class="num">Life (years)</th>
                        <th class="num">Rate a year</th>
                        <th>Basis</th>
                        <th>What the period covers badly</th>
                    </tr>
                </x-slot:head>
                @foreach ($masters['groups'] as $g)
                    <tr>
                        <td class="mono">{{ $g->AssetGroupCode }}</td>
                        <td>{{ $g->AssetGroupDescription }}</td>
                        <td class="num">{{ Format::n($g->Types) }}</td>
                        <td class="num">{{ Format::n($g->Assets) }}</td>
                        <td>{{ $g->SarsClass ?? Format::NOTHING }}</td>
                        <td class="num">{{ $g->LifeYears === null ? Format::NOTHING : Format::n($g->LifeYears, 1) }}</td>
                        <td class="num">{{ $g->AnnualRatePct === null ? Format::NOTHING : Format::pct($g->AnnualRatePct, 2) }}</td>
                        <td>{{ $g->Basis }}</td>
                        <td>{{ $g->RateNote ?? Format::NOTHING }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @elseif ($tab === 'types')
        <x-card flush>
            <x-table procedure="agora.usp_Assets_Masters" :count="$counts['types']" tools dense data-tt-page="50">
                <x-slot:head>
                    <tr><th>Group</th><th>Type</th><th>Description</th><th class="num">Assets</th></tr>
                </x-slot:head>
                @foreach ($masters['types'] as $t)
                    <tr>
                        <td class="mono">{{ $t->AssetGroupCode }}</td>
                        <td class="mono">{{ $t->AssetTypeCode }}</td>
                        <td>{{ $t->AssetTypeDescription }}</td>
                        <td class="num">{{ Format::n($t->Assets) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @elseif ($tab === 'owners')
        <x-card flush>
            <x-table procedure="agora.usp_Assets_Masters" :count="$counts['owners']" tools dense>
                <x-slot:head>
                    <tr><th>Owner</th><th class="num">Assets</th></tr>
                </x-slot:head>
                @foreach ($masters['owners'] as $o)
                    <tr>
                        <td>{{ $o->AssetOwner }}</td>
                        <td class="num">{{ Format::n($o->Assets) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @else
        <x-card flush>
            <x-table procedure="agora.usp_Assets_Masters" :count="$counts['movements']" dense>
                <x-slot:head>
                    <tr><th>Code</th><th>Movement</th><th>What it does</th><th>From</th></tr>
                </x-slot:head>
                @foreach ($masters['movementTypes'] as $m)
                    <tr>
                        <td class="mono">{{ $m->MovementType }}</td>
                        <td>{{ $m->MovementTypeDescription }}</td>
                        <td>{{ $m->WhatItDoes }}</td>
                        <td>{{ $m->Source === 'agora' ? 'Agora' : 'PumpIT' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
</x-app-shell>
