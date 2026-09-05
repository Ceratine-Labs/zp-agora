{{--
    Something the system worked out, with how sure it is and why — the Z-read
    allocation panel in the mockup, generalised.

    The shape matters more than the styling. Agora proposes and a person
    disposes, and the three parts of that contract are all required here:

      1. **What it proposes** — one line, in the display face, so it is the
         thing the eye lands on.
      2. **How confident it is** — certain / likely / review / manual, as a
         chip. Not a percentage: the customer does not have a calibrated
         probability and pretending otherwise invites the number to be trusted.
      3. **Why** — the evidence, listed. A proposal a person cannot audit is a
         proposal they will either rubber-stamp or ignore, and both are worse
         than no proposal.

    `manual` is a first-class confidence, not an absence of one. "No history
    for this operator ID" is itself information — it usually means a till
    operator has appeared that the branch has never allocated before, which is
    worth someone asking about.

    Accept and override go in the `actions` slot, because they are forms with
    permissions on them and that is the screen's business, not the component's.
--}}
@props([
    'confidence' => 'review',
    'headline' => null,
    'eyebrow' => null,
    'evidence' => [],
])

@php
    $levels = [
        'certain' => ['tone' => 'good', 'label' => 'Certain'],
        'likely' => ['tone' => 'warn', 'label' => 'Likely'],
        'review' => ['tone' => 'serious', 'label' => 'Review'],
        'manual' => ['tone' => 'crit', 'label' => 'Needs a person'],
    ];
    $level = $levels[$confidence] ?? $levels['review'];
    $evidence = is_array($evidence) ? array_values(array_filter($evidence)) : array_filter([$evidence]);
@endphp

<div {{ $attributes->merge(['class' => 'proposal conf-'.$confidence]) }}>
    <div class="proposal-h">
        <x-chip :tone="$level['tone']">{{ $level['label'] }}</x-chip>
        <span class="eyebrow">{{ $eyebrow ?? 'The system suggests' }}</span>
    </div>

    @if ($headline)<div class="proposal-val">{{ $headline }}</div>@endif

    @if ($evidence)
        <ul class="evidence">
            @foreach ($evidence as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    {{ $slot }}

    @isset($actions)<div class="proposal-actions">{{ $actions }}</div>@endisset
</div>
