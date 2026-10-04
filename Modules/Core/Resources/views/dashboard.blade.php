<x-app-shell title="Today">
    {{-- What the page is for, and what is not on it yet, sit behind the info
         button (Ryan, 4 Oct 2026: users skip standing explanation). The counts
         stay on the page. --}}
    <x-page-head eyebrow="Head office" title="Today">
        <x-slot:info>
            <p>The work that has to happen before this day can be closed.</p>
            <p>Trading figures are not here yet — the branch console and the group trading position will
               supply them. Every count on this page is real, read from the database.</p>
            <p>Administrative entities are branches for accounting only: they have no trading day to close.</p>
        </x-slot:info>
    </x-page-head>

    <x-kpi-strip>
        <x-kpi label="Trading sites" :value="$branchCount" />
        <x-kpi label="Administrative entities" :value="$entityCount" />
        <x-kpi label="Workspace" :value="$context->workspace() === 'branch' ? 'Branch' : 'Head office'"
               :note="$context->id() ? 'branch '.$context->id() : 'all sites in scope'" />
    </x-kpi-strip>
</x-app-shell>
