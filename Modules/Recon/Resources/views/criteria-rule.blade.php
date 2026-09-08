<x-app-shell :title="$area['label'].' — rule '.$order">
    <x-page-head
        eyebrow="Extraction configuration"
        :title="($branch?->Name ?? 'Site '.$branchId).' — '.$area['label']"
        :blurb="'Rule '.$order.' of this site\'s '.$area['label'].' configuration. What is in force here is what '
                .'agora.'.$area['procedure'].' and both drills resolve against every time they run.'">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.config', $area['key']) }}">All rules</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('refusal'))
        <x-notice tone="stop" title="Nothing was changed" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
        </x-notice>
    @endif

    @if (session('configured'))
        <x-notice tone="info" :title="session('configured')" style="margin-bottom:16px">
            <p>Every preview and both drills resolve against this from now on. Nothing was written to the
               customer's <code>BRN_AutoReconCriteria</code> — reverting is switching the override off.</p>
        </x-notice>
    @endif

    {{--
        The SAME partial the modal fetches, wrapped in the shell.

        One rule, one form, rendered two ways: the grid's row action opens it in
        a dialog over the list, and this is what a person gets who followed the
        site's own link, pasted the address, or has no JavaScript. Until this
        view existed the route answered both with the bare fragment — so the
        link off the grid landed on a page with no styling and no way back,
        which is exactly how Ryan found it on live.
    --}}
    <x-card :title="'Rule '.$order" sub="The customer's row, Agora's override, and the form that changes it">
        @include('recon::partials.criteria-edit')
    </x-card>
</x-app-shell>
