{{--
    One chain, as a PAGE.

    THE SAME BUG ReconController::configEdit CARRIES A PARAGRAPH ABOUT, and I
    reproduced it. `line()` returned the bare fragment to everybody, so
    row-detail.js got exactly what it wanted — and a person who followed the
    URL, or pasted it, or opened it in a new tab, landed on unstyled markup
    with no navigation and no way back. Ryan found it on live at
    /app/stock-recon/runs/2/lines/7222 on 9 September 2026, the second time
    this module has been caught by it.

    The fragment renders identically inside this shell, so there is one copy of
    the markup and two ways in: an XHR gets the fragment, a person gets a page.
--}}
<x-app-shell :title="($item?->StockItemDescription ?? $line->StockItemNo).' — run #'.$run->Id" wide>
    <x-page-head
        eyebrow="Stock recon centre"
        :title="$item?->StockItemDescription ?? $line->StockItemNo"
        :blurb="'Every shift of this item in run #'.$run->Id.'\'s window, counted beside balanced. '
                .'The row you came from is marked.'">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.run', $run) }}">Back to the proposals</a>
            <a class="btn-ghost" href="{{ route('app.stockrecon.exceptions', $run) }}">Exceptions</a>
        </x-slot:actions>
    </x-page-head>

    <x-card flush>
        @include('stockrecon::partials.chain-detail')
    </x-card>
</x-app-shell>
