@props(['name' => 'book'])

<svg {{ $attributes->merge(['class' => 'sr-tutorial-icon']) }} width="24" height="24" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    @switch($name)
        @case('box')
            <path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7.5 5l9 4v4" />
            @break
        @case('link')
            <path d="m10 13 4-4m-6 6-1 1a3.5 3.5 0 0 1-5-5l4-4a3.5 3.5 0 0 1 5 0m2 10a3.5 3.5 0 0 0 5 0l4-4a3.5 3.5 0 0 0-5-5l-1 1" />
            @break
        @case('scale')
            <path d="M12 3v17M6 21h12M3 7h18M6 7l-4 8h8L6 7Zm12 0-4 8h8l-4-8Z" />
            @break
        @case('alert')
            <path d="m12 3 10 18H2L12 3Zm0 6v5m0 3v.2" />
            @break
        @case('receipt')
            <path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Zm4 4h6m-6 4h6m-6 4h3" />
            @break
        @case('shield')
            <path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Zm-4 10 3 3 5-6" />
            @break
        @case('check')
            <circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" />
            @break
        @case('search')
            <circle cx="10" cy="10" r="6" /><path d="m15 15 6 6" />
            @break
        @case('history')
            <path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-4v5l4 2" />
            @break
        @default
            <path d="M12 5c-3-2-6-2-10-1v15c4-1 7-1 10 1 3-2 6-2 10-1V4c-4-1-7-1-10 1Zm0 0v15M6 8h2m8 0h2M6 12h2m8 0h2" />
    @endswitch
</svg>
