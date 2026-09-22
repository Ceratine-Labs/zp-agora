{{--
    One stock line, whole (T025).

    Four questions in the order somebody asks them: what is this line, what
    does it cost and make, how does the counting behave, and — when Agora
    holds an override — what the customer's own table says instead. That last
    card is the most important thing on the page when it appears, because it
    means this screen and PumpIT are two different answers.

    The editor is a modal on this page rather than a screen of its own: the
    page load IS the fresh read, so modal.js's fetch-per-open would be a
    second way to do the same thing. Every rule it can break lives in
    agora.usp_Product_SaveStockItem, and a refusal comes back as the notice
    at the top rather than as an error page.
--}}
@php
    $isOverride = trim((string) $item->Source) === 'agora';
    $posSellIncl = $item->PosSellPrice === null ? null : (float) $item->PosSellPrice * 1.15;
    $priceDiffers = $posSellIncl !== null
        && (float) $item->PosSellPrice > 0
        && abs((float) $item->SellingPrice - $posSellIncl) > 0.01;
    $hasGp = (float) $item->PosSellPrice > 0 && (float) $item->CostPrice > 0;
@endphp

<x-app-shell :title="$item->StockItemDescription ?? $item->StockItemNo">
    <x-page-head
        eyebrow="Setup · Trading rules · Stock recon master"
        :title="$item->StockItemDescription ?? 'Item '.$item->StockItemNo"
        :blurb="$item->BranchName.' · item '.$item->StockItemNo.' · '.$item->PosSystem.' '.$item->POSCode">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.master.stock.index', ['branch' => $item->BranchId]) }}">Back to the list</a>
            @can('master.stock.edit')
                <button type="button" class="btn-primary" data-modal-open="edit-stock-item">Edit</button>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That change was refused">{{ $message }}</x-notice>
    @enderror

    @if (! $item->IsActive)
        <x-notice tone="warn" title="Retired">
            This line has been retired in Agora. It stays here and stays in the customer's estate — the
            history that references it has to remain readable — but it should not be offered on a new
            counting sheet.
        </x-notice>
    @endif

    <x-kpi-strip>
        <x-kpi
            label="Sell (incl VAT)"
            :value="\App\Support\Format::r($item->SellingPrice)"
            :note="$item->PriceType" />
        <x-kpi
            label="Cost (excl VAT)"
            :value="$item->CostPrice === null ? '—' : \App\Support\Format::r($item->CostPrice)"
            :note="$item->CostPrice === null ? 'no POS record for this code' : 'from the POS cost file'" />
        <x-kpi
            label="On hand"
            :value="$item->QtyOnHand === null ? '—' : \App\Support\Format::n($item->QtyOnHand, 0)"
            :note="$item->UOMCode" />
        <x-kpi
            label="Last counted"
            :value="$item->LastCountedAt ? \Illuminate\Support\Carbon::parse($item->LastCountedAt)->format('j M Y') : '—'"
            :note="$item->LastCountedAt ? ($item->CountLines90 ?? 0).' counts in 90 days' : 'never counted'"
            :tone="$item->LastCountedAt ? null : 'warn'" />
    </x-kpi-strip>

    <x-card title="What this line is">
        <x-facts>
            <x-fact label="Item number" :value="$item->StockItemNo">
                Unique within this site only. The same number is a different product at the other twenty-one.
            </x-fact>
            <x-fact label="POS system" :value="$item->PosSystem">
                Which till system carries it. PumpIT calls this column Location; it is not a place.
            </x-fact>
            <x-fact label="POS code" :value="$item->POSCode">
                Unique per site AND POS system, not per site.
            </x-fact>
            <x-fact label="Counting area" :value="$area?->AreaDescription ?? 'Area '.$item->AreaNo">
                {{ $area?->AreaGroup ?? 'No area group' }}{{ $area?->LossGraceMonthly === null ? '' : ' · monthly loss grace '.\App\Support\Format::r($area->LossGraceMonthly) }}
            </x-fact>
            <x-fact label="Unit" :value="$item->UOMCode">
                Issue multiple {{ \App\Support\Format::n($item->IssueMultiple, 2) }}.
            </x-fact>
            <x-fact label="Held by" :value="$isOverride ? 'Agora override' : 'The customer\'s master'">
                {{ $isOverride
                    ? 'This row replaces the legacy one entirely. Park it and the customer\'s comes back.'
                    : 'Read straight from PumpIT.dbo.STK_StockMaster. Agora has never changed it.' }}
            </x-fact>
        </x-facts>
    </x-card>

    <x-card title="What it costs and makes" sub="From that site's POS cost file, joined on branch, POS system and code">
        @if ($item->CostPrice === null)
            <x-empty-state
                title="No POS record for this code"
                text="The cost file has no row for this branch, POS system and code, so there is no cost, no quantity on hand and no category to show. 157 lines across the estate are in this position." />
        @else
            <x-facts>
                <x-fact label="Cost (excl)" :value="\App\Support\Format::r($item->CostPrice)">
                    STDCOST, ex-VAT.
                </x-fact>
                <x-fact label="POS sell (excl)" :value="\App\Support\Format::r($item->PosSellPrice)">
                    @if ($priceDiffers)
                        The master sells at {{ \App\Support\Format::r($item->SellingPrice) }} where this implies
                        {{ \App\Support\Format::r($posSellIncl) }} including VAT. A real difference, not rounding.
                    @else
                        Consistent with the master's inclusive price.
                    @endif
                </x-fact>
                <x-fact
                    label="GP"
                    :value="$hasGp ? \App\Support\Format::pct(((float) $item->PosSellPrice - (float) $item->CostPrice) * 100 / (float) $item->PosSellPrice) : null"
                    :tone="$hasGp ? null : 'warn'">
                    @if ($hasGp)
                        Both halves ex-VAT, so no VAT rate is assumed anywhere.
                    @else
                        No GP: the POS file carries {{ (float) $item->CostPrice > 0 ? 'no sell price' : 'no cost price' }} for this code.
                    @endif
                </x-fact>
                <x-fact label="Category" :value="$item->Category">
                    The POS file's, because the stock master has no category of its own.
                </x-fact>
                <x-fact
                    label="Last sold"
                    :value="$item->LastSoldAt ? \Illuminate\Support\Carbon::parse($item->LastSoldAt)->format('j M Y') : null">
                    {{ \App\Support\Format::n($item->QtySoldThisMonth ?? 0, 0) }} sold this month.
                </x-fact>
                <x-fact label="Critical line" :value="$item->IsCritical ? 'Yes' : 'No'">
                    On the list of lines that must never be out of stock.
                </x-fact>
            </x-facts>
        @endif
    </x-card>

    <x-card title="How the counting behaves" collapsible remember="product.stock.show.counting">
        <x-facts>
            <x-fact label="Variance allowance" :value="\App\Support\Format::n($item->QtyVarAllowance, 2)">
                Above this a count is flagged.
            </x-fact>
            <x-fact label="Closing quantity" :value="$item->IsDoCloseQtyCalc ? 'Calculated' : 'Typed'">
                Whether the counter enters the close or the system works it out.
            </x-fact>
            <x-fact label="Negative issue" :value="$item->IsAllowNegativeQtyIssued ? 'Allowed' : 'Refused'">
                Negative close {{ $item->IsAllowNegativeQtyClose ? 'allowed' : 'refused' }}.
            </x-fact>
            <x-fact label="Monitored" :value="$item->IsMonitoredItem ? 'Yes' : 'No'">
                A watched line.
            </x-fact>
            <x-fact label="Pre-production" :value="$item->IsStockItemPreProduction ? 'Yes' : 'No'">
                {{ $item->IsStockItemPreProduction
                    ? 'Ratio '.\App\Support\Format::n($item->Ratio, 2).', type '.$item->PreProductionTypeNo.'.'
                    : 'Bought in, not made here.' }}
            </x-fact>
            <x-fact label="Counted" :value="\App\Support\Format::n($item->CountLinesAllTime ?? 0, 0).' times'">
                {{ $item->CountStatsRefreshedAt
                    ? 'Rollup rebuilt '.\Illuminate\Support\Carbon::parse($item->CountStatsRefreshedAt)->format('j M Y, H:i').'.'
                    : 'The rollup has never been rebuilt, so this is not yet an answer.' }}
            </x-fact>
        </x-facts>
    </x-card>

    @if ($isOverride && $legacy !== null)
        <x-card title="Beside the customer's own row" sub="An override replaces its legacy row whole — this is what it replaced">
            <x-compare
                :left="[
                    'Description' => $legacy->StockItemDescription,
                    'POS code' => $legacy->POSCode,
                    'Counting area' => $legacy->AreaNo,
                    'Selling price' => \App\Support\Format::r($legacy->SellingPrice),
                    'Price type' => $legacy->PriceType,
                    'Unit' => $legacy->UOMCode,
                ]"
                :right="[
                    'Description' => $item->StockItemDescription,
                    'POS code' => $item->POSCode,
                    'Counting area' => $item->AreaNo,
                    'Selling price' => \App\Support\Format::r($item->SellingPrice),
                    'Price type' => $item->PriceType,
                    'Unit' => $item->UOMCode,
                ]"
                leftTitle="PumpIT — the customer's row"
                rightTitle="Agora — the override"
                live="right" />
        </x-card>
    @endif

    @can('master.stock.edit')
        <x-modal id="edit-stock-item" title="Change this stock line" wide>
            <form method="POST" action="{{ route('app.master.stock.update', ['branch' => $item->BranchId, 'item' => $item->StockItemNo]) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="StockItemNo" value="{{ $item->StockItemNo }}">

                <p class="field-help" style="margin-top:0">
                    Saving writes an <strong>Agora override</strong>. It replaces the customer's row for this
                    line entirely and PumpIT's own table is never touched — which also means PumpIT's stock
                    counts go on reading their copy until cutover. Park the override and their row is back
                    in force, with the reason kept.
                </p>

                <div class="field-row">
                    <x-field name="StockItemDescription" label="Description"
                             :value="old('StockItemDescription', $item->StockItemDescription)"
                             help="What the counter reads on the sheet." />
                    <x-field name="POSCode" label="POS code"
                             :value="old('POSCode', $item->POSCode)"
                             help="Unique per site AND POS system — not per site." />
                </div>

                <div class="field-row">
                    <x-field name="PosSystem" label="POS system"
                             :choices="['ARCH' => 'ARCH', 'WINBRANCH' => 'WINBRANCH', 'AURA' => 'AURA', 'NAMOS' => 'NAMOS', 'PILOT' => 'PILOT', 'ARCHLIQ' => 'ARCHLIQ']"
                             :value="old('PosSystem', $item->PosSystem)"
                             help="Which till system carries it. Not a place." />
                    <x-field name="AreaNo" label="Counting area"
                             :choices="collect($areas)->mapWithKeys(fn ($a) => [$a->AreaNo => $a->AreaNo.' · '.$a->AreaDescription])->all()"
                             :value="old('AreaNo', $item->AreaNo)"
                             help="Areas are numbered per site." />
                </div>

                <div class="field-row">
                    <x-field name="PriceType" label="Price type"
                             :choices="['Selling Price' => 'Selling Price', 'Set Price' => 'Set Price', 'Factor' => 'Factor']"
                             :value="old('PriceType', $item->PriceType)"
                             help="Selling Price and Factor are RECOMPUTED per branch by PumpIT out of the POS cost file — a value typed here will be overwritten there." />
                    <x-field name="SellingPrice" label="Sell (incl VAT)" type="number" step="0.0001" min="0"
                             :value="old('SellingPrice', $item->SellingPrice)"
                             help="Four decimal places, because fifty live prices carry more than two." />
                </div>

                <div class="field-row">
                    <x-field name="UOMCode" label="Unit"
                             :choices="['Each' => 'Each', 'KG' => 'KG', 'LTR' => 'LTR']"
                             :value="old('UOMCode', $item->UOMCode)" />
                    <x-field name="Factor" label="Factor" type="number" step="0.0001"
                             :value="old('Factor', $item->Factor)"
                             help="Multiplier on cost when the price type is Factor." />
                </div>

                {{-- The four with no server-side rule anywhere in PumpIT. The old
                     ASP application enforces them and nothing here invents a
                     replacement — see the procedure header. --}}
                <details>
                    <summary>How the counting behaves</summary>
                    <div class="field-row">
                        <x-field name="QtyVarAllowance" label="Variance allowance" type="number" step="0.0001"
                                 :value="old('QtyVarAllowance', $item->QtyVarAllowance)"
                                 help="Above this a count is flagged." />
                        <x-field name="IssueMultiple" label="Issue multiple" type="number" step="0.0001"
                                 :value="old('IssueMultiple', $item->IssueMultiple)"
                                 help="Stored as sent. PumpIT has no server-side rule for this — the old application enforces it, and Agora will not invent one." />
                    </div>
                    <div class="field-row">
                        <x-field name="IsDoCloseQtyCalc" label="Closing quantity is calculated" type="bool"
                                 :value="old('IsDoCloseQtyCalc', $item->IsDoCloseQtyCalc)" />
                        <x-field name="IsMonitoredItem" label="Monitored line" type="bool"
                                 :value="old('IsMonitoredItem', $item->IsMonitoredItem)" />
                    </div>
                    <div class="field-row">
                        <x-field name="IsAllowNegativeQtyIssued" label="Allow negative issue" type="bool"
                                 :value="old('IsAllowNegativeQtyIssued', $item->IsAllowNegativeQtyIssued)" />
                        <x-field name="IsAllowNegativeQtyClose" label="Allow negative close" type="bool"
                                 :value="old('IsAllowNegativeQtyClose', $item->IsAllowNegativeQtyClose)" />
                    </div>
                </details>

                <x-field name="IsActive" label="In use" type="bool"
                         :value="old('IsActive', $item->IsActive)"
                         help="Unticking retires the line. It stays in the estate and in every count that references it — it just stops being offered." />

                <x-field name="reason" label="Why" :value="old('reason')"
                         help="Kept with the override. Required, because a master change nobody can explain later is what this table exists to end." />

                <div class="btn-row">
                    <button type="submit" name="action" value="save" class="btn-primary">Save the override</button>
                    @if ($isOverride)
                        <button type="submit" name="action" value="park" class="btn-ghost"
                                data-confirm
                                data-confirm-text="The customer's own row comes back into force for this line. The override stays here with its reason.">Park it</button>
                    @endif
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-shell>
