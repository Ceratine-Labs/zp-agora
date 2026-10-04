{{--
    Asset movements (T057): every transfer, repair trip, sale, trade-in and
    write-off, reversed ones included. Recording one is a modal here; an
    asset's own page has the same form with the asset already chosen, and the
    reverse button for its latest movement.

    What the list holds is behind the info button beside the title (4 Oct 2026).
--}}
<x-app-shell title="Asset movement">
    <x-page-head eyebrow="People and assets" title="Asset movement">
        <x-slot:info>
            <p>Assets transferred between sites, sent for repair and back, sold, traded in or written off.
               Reversed movements stay on the list.</p>
            @if ($maySave)
                <p>Any asset still on a register at the sites you can see can be moved; a disposed one cannot.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.register') }}">Register</a>
            <a class="btn" href="{{ route('app.assets.repairs') }}">Repairs</a>
            @if ($maySave)
                <button type="button" class="btn-primary" data-modal-open="record-movement">Record a movement</button>
            @endif
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Recorded">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That movement was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That movement was not sent">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    <x-data-grid :grid="$grid" :branches="$gridBranches" surface />

    @if ($maySave)
        @include('assets::movement.partials.form', [
            'modalId' => 'record-movement',
            'assetKey' => null,
            'assetLabel' => null,
        ])
    @endif
</x-app-shell>
