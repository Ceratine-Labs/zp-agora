<x-app-shell title="Modules">
    <x-page-head :eyebrow="$context->workspace() === 'branch' ? 'Branch workspace' : 'Head office'"
                 title="Choose a module"
                 blurb="Open the tools you need for your day." />

    <x-module-menu :modules="$modules" />
</x-app-shell>
