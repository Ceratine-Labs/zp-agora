<x-app-shell title="Stock reconciliation logic">
    <x-page-head eyebrow="Control · stock · management guide" title="Stock reconciliation logic"
                 blurb="Follow the calculation, understand each correction, and verify the financial result.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.stockrecon.index') }}">Back to stock recon</a>
        </x-slot:actions>
    </x-page-head>

    <x-stockrecon::tutorial-deck :content="$content" :lesson="$lesson" />
</x-app-shell>
