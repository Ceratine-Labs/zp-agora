{{--
    Where you are — the mockup's `crumb`.

    A real <nav> with an ordered list under it, because the trail is a
    hierarchy and a screen reader should be able to say "breadcrumb, 3 items"
    rather than reading a run of separators. The separators themselves are
    `aria-hidden`; they are punctuation, not content.

    The last part is the current page and carries no link, per the mockup and
    per the rule that a reference which leads nowhere is not rendered as a
    link.

    `parts` is a list of ['label' =>, 'href' =>], or plain strings.
--}}
@props(['parts' => []])

@php
    $parts = collect($parts)
        ->map(fn ($part) => is_array($part) ? $part : ['label' => $part])
        ->values();
@endphp

<nav {{ $attributes->merge(['class' => 'crumb']) }} aria-label="Breadcrumb">
    <ol>
        @foreach ($parts as $part)
            @if (! $loop->first)<li class="sep" aria-hidden="true">›</li>@endif
            <li>
                @if (! empty($part['href']) && ! $loop->last)
                    <a href="{{ $part['href'] }}">{{ $part['label'] }}</a>
                @elseif ($loop->last)
                    <b aria-current="page">{{ $part['label'] }}</b>
                @else
                    <span>{{ $part['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
