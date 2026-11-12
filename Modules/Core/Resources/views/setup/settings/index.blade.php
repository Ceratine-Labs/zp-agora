<x-app-shell title="System defaults and thresholds">
    <x-page-head eyebrow="Setup · The business" title="System defaults and thresholds"
                 blurb="The figures the screens decide by — variance tolerances, reason thresholds, leniency, proposal confidence — for the group, and for any site that carries its own.">
        <x-slot:info>
            <p>Each setting has a <strong>group value</strong>, which every site follows. Where a setting allows it, a site can be given <strong>its own value</strong>, and that wins at that site; take it away and the site follows the group again.</p>
            <p>A setting marked "one value for every site" is read by a screen or report that covers the whole estate at once, so a site value would make it disagree with itself.</p>
            <p>Every change needs a reason and is kept in the setting's history. Copies of PumpIT's own settings (SS_SysDefaults, BRN_Config) are listed for reference: Agora does not read them and PumpIT is not written, so they cannot be changed here.</p>
        </x-slot:info>
    </x-page-head>
    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    <x-data-grid :grid="$grid" surface />
</x-app-shell>
