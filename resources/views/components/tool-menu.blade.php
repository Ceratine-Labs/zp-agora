@props(['groups' => [], 'label' => 'Module'])

<nav class="module-hub" aria-label="{{ $label }} tools" data-module-tools>
    @foreach ($groups as $group)
        <section class="module-hub-group" aria-labelledby="module-group-{{ $loop->index }}">
            <h2 id="module-group-{{ $loop->index }}" class="module-hub-title">{{ $group['label'] }}</h2>
            <div class="module-menu">
                @foreach ($group['tools'] as $tool)
                    <a class="module-menu-item" href="{{ $tool['href'] }}" data-tool-link="{{ $tool['path'] }}"
                       data-loader="Opening {{ $tool['label'] }}…">
                        <x-feature-art :art="$tool['art']" class="module-menu-art" />
                        <span class="module-menu-copy">
                            <span class="module-menu-title">{{ $tool['label'] }}</span>
                            @if ($tool['description'])<span class="module-menu-description">{{ $tool['description'] }}</span>@endif
                        </span>
                        <svg class="module-menu-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M5 12h14m-6-6 6 6-6 6" />
                        </svg>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</nav>
