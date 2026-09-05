<x-app-shell title="Today">
    <x-page-head
        eyebrow="Head office"
        title="Today"
        blurb="The work that has to happen before this day can be closed." />

    <x-kpi-strip>
        <x-kpi label="Trading sites" :value="$branchCount" note="from your branch list" />
        <x-kpi label="Administrative entities" :value="$entityCount" note="no trading day to close" />
        <x-kpi label="Workspace" :value="$context->workspace() === 'branch' ? 'Branch' : 'Head office'"
               :note="$context->id() ? 'branch '.$context->id() : 'all sites in scope'" />
    </x-kpi-strip>

    <x-card title="The shell is up" sub="What this page proves, and what it does not">
        <p>The menu above is read from <code>agora.MenuSection</code> and <code>agora.MenuItem</code> — no
           blade file lists a link. Items nest through <code>ParentId</code> to any depth, and the panel
           renders whatever depth the data has.</p>
        <p>What is <em>not</em> here yet: trading figures. The branch console (T030) and the group trading
           position (T068) supply those. Everything on this page is a real count from the customer's
           database, not a placeholder.</p>
    </x-card>
</x-app-shell>
