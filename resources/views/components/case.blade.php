{{--
    One worked case: what it is, what happened, why, and what is being done
    about it — then the evidence underneath.

    Built for the stock recon duplicate issues (Ryan, 1 Oct 2026: "a view like
    the customer report on the stock recon itself"), whose report reads each
    case the same way: a badge saying what kind of case it is, a heading naming
    it, a short definition list — What happened / Why it is clear / Result —
    and the table that proves it. It sits behind <x-tabs chips>, one case per
    tab, but needs nothing from it.

    NOT <x-notice>: a notice is something the reader must take in before the
    figures mean what they look like; a case IS the figures, and it carries its
    own state and its own presses. NOT <x-facts>: a fact is a value with a line
    under it, and these terms are sentences — rendered at fact weight, a
    sentence becomes a headline.

      tone       the kind of case — the rail and the badge: good, warn,
                 serious, crit or neutral, checked against that list
      badge      what kind of case it is ("Clear duplicate (D1)")
      state      where it stands now ("Will be set to 0"), in its own chip
      stateTone  that chip's tone; the case's tone when left out
      title      the case, named — site · area · item · date
      meta       one muted line under the title: codes, a price, a count
      terms      ['What happened' => '…', 'Why' => '…', 'Result' => '…'];
                 a value that is Htmlable is rendered as it is, a string is
                 escaped
      actions    slot: the presses for this case, behind their permissions —
                 rendered only when the caller put something in it
      slot       the evidence, under the card: a table, usually

    Two columns of terms on a desk, one on a phone, where a 150px label column
    would leave the sentence forty pixels.
--}}
@props([
    'tone' => 'neutral',
    'badge' => null,
    'state' => null,
    'stateTone' => null,
    'title' => null,
    'meta' => null,
    'terms' => [],
])

@php
    $tones = ['good', 'warn', 'serious', 'crit', 'neutral'];
    $tone = in_array($tone, $tones, true) ? $tone : 'neutral';
    $stateTone = in_array($stateTone, $tones, true) ? $stateTone : $tone;
@endphp

<article {{ $attributes->merge(['class' => 'case case-'.$tone]) }}>
    <div class="case-card">
        @if ($badge || $state)
            <div class="case-head">
                @if ($badge)<x-chip :tone="$tone" :dot="false">{{ $badge }}</x-chip>@endif
                @if ($state)<x-chip :tone="$stateTone">{{ $state }}</x-chip>@endif
            </div>
        @endif

        @if ($title)<h4 class="case-title">{{ $title }}</h4>@endif
        @if ($meta)<p class="case-meta">{{ $meta }}</p>@endif

        @if (! empty($terms))
            <dl class="case-terms">
                @foreach ($terms as $label => $text)
                    @continue($text === null || $text === '')
                    <dt>{{ $label }}</dt>
                    <dd>{{ $text }}</dd>
                @endforeach
            </dl>
        @endif

        @if (isset($actions) && trim($actions) !== '')
            <div class="case-actions">{{ $actions }}</div>
        @endif
    </div>

    @if (trim($slot) !== '')
        <div class="case-evidence">{{ $slot }}</div>
    @endif
</article>
