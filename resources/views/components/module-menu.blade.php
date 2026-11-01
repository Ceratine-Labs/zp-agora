{{-- Destinations come from the same permitted navigation tree as the app bar. --}}
@props(['modules' => []])

@if (count($modules))
    <nav {{ $attributes->merge(['class' => 'module-menu']) }} aria-label="Modules">
        @foreach ($modules as $module)
            <a class="module-menu-item" href="{{ $module['href'] }}"
               data-loader="Opening {{ $module['label'] }}…" data-module-link="{{ $module['key'] }}">
                <span class="module-menu-art" aria-hidden="true">
                    <x-module-art :variant="$module['art']" />
                </span>
                <span class="module-menu-copy">
                    <span class="module-menu-title">{{ $module['label'] }}</span>
                    <span class="module-menu-description">{{ $module['description'] }}</span>
                </span>
                <svg class="module-menu-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M5 12h14m-6-6 6 6-6 6" />
                </svg>
            </a>
        @endforeach
    </nav>
@else
    <x-notice tone="info" title="No modules available">
        <p>Ask your administrator to give you access to the modules you need.</p>
    </x-notice>
@endif
