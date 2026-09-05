{{--
    "Needs a decision" — the queue of things waiting on a person rather than on
    the system.

    Staff shorts to approve, purchase requests to sign, a Z-read nobody has
    allocated. Each row names who or what it is about, what kind of thing it
    is, and what it is worth, and goes to the screen where the decision is
    actually made.

    Deliberately not a grid. This is a short list of things a manager has to
    act on this morning; a sortable table with header filters would be the
    wrong instrument, and it would put the decision behind a column chooser.

    `items` is a list of ['who' =>, 'what' =>, 'detail' =>, 'amount' =>,
    'href' =>, 'tone' =>]. `amount` arrives already formatted.
--}}
@props(['items' => [], 'empty' => 'Nothing waiting.'])

<div {{ $attributes->merge(['class' => 'decisions']) }}>
    @forelse ($items as $item)
        @php($tag = ! empty($item['href']) ? 'a' : 'div')
        <{{ $tag }} class="decision tone-{{ $item['tone'] ?? 'neutral' }}"
            @if (! empty($item['href'])) href="{{ $item['href'] }}" @endif>
            <span class="decision-main">
                <span class="who">{{ $item['who'] ?? '' }}</span>
                <span class="asat">{{ trim(($item['what'] ?? '').(! empty($item['detail']) ? ' · '.$item['detail'] : ''), ' ·') }}</span>
            </span>
            @isset($item['amount'])<b class="amount">{{ $item['amount'] }}</b>@endisset
        </{{ $tag }}>
    @empty
        <x-empty-state :text="$empty" />
    @endforelse
</div>
