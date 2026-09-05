{{--
    What the system knows before anybody has signed in — the mockup's
    `signin-state`.

    Overnight loads that failed, exceptions open, Z-reads unallocated, purchase
    requests waiting. It sits on the sign-in hero on purpose: the first useful
    thing Agora can tell a branch manager at 05:00 is whether last night's
    loads landed, and making them authenticate first to find out is a screen
    designed for the system rather than the person.

    Every row carries a text label as well as its dot, because a coloured dot
    on its own is not a status anybody can read out.

    `rows` is a list of ['tone' =>, 'text' =>, 'href' =>]. Tones are good,
    warn, crit. Counts arrive already through App\Support\Format.
--}}
@props(['title' => null, 'rows' => []])

<div {{ $attributes->merge(['class' => 'signin-state']) }}>
    @if ($title)<p class="eyebrow">{{ $title }}</p>@endif

    @foreach ($rows as $row)
        @php($tone = in_array($row['tone'] ?? 'good', ['good', 'warn', 'crit'], true) ? $row['tone'] : 'good')
        @php($tag = ! empty($row['href']) ? 'a' : 'div')
        <{{ $tag }} class="signin-state-row" @if (! empty($row['href'])) href="{{ $row['href'] }}" @endif>
            <span class="sdot {{ $tone }}" aria-hidden="true"></span>
            <span>{{ $row['text'] ?? '' }}</span>
        </{{ $tag }}>
    @endforeach
</div>
