{{--
    A row of figures that belong to one result set.

    Not the same thing as <x-kpi>. A KPI is a headline about the business; a
    stat strip describes the answer immediately below it — how many rows, how
    much on each side, what the difference is. It sits inside the card it
    describes rather than at the top of the page.

    `stats` is a list of ['label' => , 'value' => , 'note' => , 'tone' => ].
--}}
@props(['stats' => []])

<div {{ $attributes->merge(['class' => 'statstrip']) }}>
    @foreach ($stats as $stat)
        <div class="stat tone-{{ $stat['tone'] ?? 'neutral' }}">
            <span class="stat-label">{{ $stat['label'] }}</span>
            <span class="stat-value">{{ $stat['value'] }}</span>
            @if (! empty($stat['note']))<span class="stat-note">{{ $stat['note'] }}</span>@endif
        </div>
    @endforeach
</div>
