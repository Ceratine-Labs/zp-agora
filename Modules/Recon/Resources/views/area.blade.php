{{-- The two tabs whose content is a wide grid use the window; the ones
     that are a form keep the reading measure. --}}
<x-app-shell :title="$area['label'].' — auto reconciliation'"
             :wide="in_array($tab, ['config', 'runs'], true)">
    <x-page-head
        eyebrow="Auto reconciliation"
        :title="$area['label']"
        :blurb="$area['blurb']">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.index') }}">All areas</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('discarded') !== null)
        <x-notice tone="info" :title="session('discarded') === 0 ? 'Nothing to discard' : session('discarded').' '.Str::plural('preview', session('discarded')).' discarded'" style="margin-bottom:16px">
            <p>Previews only. Nothing in the customer's databases was touched, and any run that had been
               executed was kept — a committed run is the only record of what it stamped.</p>
        </x-notice>
    @endif

    @if (session('refusal'))
        {{-- "Nothing to reconcile" and "this branch has no rule configured" are
             opposite answers, and on the live system that distinction is
             finding 1: twenty-four of twenty-six branches produce nothing and
             the screen never says why. --}}
        {{-- Open by default: a refusal is the answer, not context for one. --}}
        <x-notice tone="stop" title="The procedure could not run for this branch" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
            @if (session('refusalDetail'))
                <p><code>{{ collect(session('refusalDetail'))->map(fn ($v, $k) => "$k = ".($v ?? 'null'))->implode('   ') }}</code></p>
            @endif
            @if (session('refusalProcedure'))
                <p class="muted">Reported by <code>{{ session('refusalProcedure') }}</code>.
                   Nothing was read or written beyond the configuration lookup.</p>
            @endif
        </x-notice>
    @endif

    {{--
        Four faces of one area, and they are LINKS.

        Link mode, not panel mode: each pane is its own result set with its own
        scope, so a tab is somewhere you can send someone — the rule everywhere
        else in Agora and the reason <x-tabs> has the mode at all. No
        JavaScript is involved.

        The strip is built in the controller so it can drop a tab the person
        may not open, rather than offering a door that answers 403.
    --}}
    <x-tabs :items="$tabs" :active="$tab" label="Reconciliation area" style="margin-bottom:16px" />

    @include('recon::panes.'.$tab)
</x-app-shell>
