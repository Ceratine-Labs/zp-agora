<x-app-shell title="Stock recon tutorial">
    <x-page-head eyebrow="Control · stock · learning" title="Stock recon, explained"
                 blurb="Follow the stock, understand the exceptions, then work through a run with confidence.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.stockrecon.index') }}">Back to stock recon</a>
        </x-slot:actions>
    </x-page-head>

    <x-stockrecon::tutorial-deck :content="$content" :lesson="$lesson" />
</x-app-shell>
