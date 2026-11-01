<x-app-shell :title="$module['label']">
    <x-page-head eyebrow="Modules" :title="$module['label']" :blurb="$module['description']"
                 :art="['kind' => 'module', 'variant' => $module['art']]">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.dashboard', ['ws' => $context->workspace()]) }}">All modules</a>
        </x-slot:actions>
    </x-page-head>

    <x-tool-menu :groups="$module['groups']" :label="$module['label']" />
</x-app-shell>
