@props(['title' => null, 'sub' => null, 'flush' => false])

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title)
        <header class="card-head">
            <div>
                <h2>{{ $title }}</h2>
                @if ($sub)<p class="card-sub">{{ $sub }}</p>@endif
            </div>
            @isset($actions)<div class="card-actions">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div class="card-body {{ $flush ? 'flush' : '' }}">{{ $slot }}</div>
</section>
