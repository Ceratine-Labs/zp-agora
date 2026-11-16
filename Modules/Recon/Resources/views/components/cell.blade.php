{{--
    One cell of the bank recon pivot: <x-recon::cell :cell="$cell" />.

    It writes the <td> and nothing else. Everything in it was decided by
    Modules\Recon\Services\ReconPivot — the figure (already formatted), its
    colour, its glyph, its link, its tooltip — so this never formats a number
    and never decides anything. A cell that formatted here would be a second
    opinion about the same figure, and the export would not see it.

      text   the figure or glyph, as shown
      sort   data-sort: the raw amount (2 dp) or count; empty sorts last
      tone   washes the figure in <x-tone-value> (warn: not declared, crit:
             not in bank); null for none
      state  span.rp-{state}: a glyph (clear stopped never unused unknown),
             transit (dashed, italic) or recent (the Dates view)
      class  the <td>'s classes: num, grp, rp-flag (a stopped side that still
             has rows)
      href   the rows behind the figure; null for glyphs and blanks
      tip    data-tip HTML, built from e()-escaped parts — Blade escapes it once
             more into the attribute and tip.js reads it back as HTML
      aria   the same in one plain sentence (a link's starts with its figure)

    A span that carries a tip but no link takes tabindex="0", so its tip is
    reachable from the keyboard as tip.js shows it on focus. A span that is
    named (aria-label) is role="img": ARIA 1.2 does not let a generic element
    carry a name, and a screen reader may drop it. A glyph or figure with no
    link and no wash is ONE span, its rp-{state} class on it.

    Props: cell (array from ReconPivot)
--}}
@props(['cell'])

@php
    $text = (string) ($cell['text'] ?? '');
    $tone = $cell['tone'] ?? null;
    $state = $cell['state'] ?? null;
    $href = $cell['href'] ?? null;
    $tip = $cell['tip'] ?? null;
    $aria = $cell['aria'] ?? null;
    $tag = $href ? 'a' : 'span';
    $role = ! $href && $aria;
    $own = ! $href && ! $tone && $state ? 'rp-'.$state : null;
@endphp

<td class="{{ $cell['class'] ?? 'num' }}" data-sort="{{ $cell['sort'] ?? '' }}">@if ($text !== '' || $tip)<{{ $tag }}@if ($href) href="{{ $href }}"@elseif ($tip) tabindex="0"@endif @if ($own) class="{{ $own }}"@endif @if ($role) role="img"@endif @if ($tip) data-tip="{{ $tip }}"@endif @if ($aria) aria-label="{{ $aria }}"@endif>@if ($tone)<x-tone-value :tone="$tone">@if ($state)<span class="rp-{{ $state }}">{{ $text }}</span>@else{{ $text }}@endif</x-tone-value>@elseif ($state && ! $own)<span class="rp-{{ $state }}">{{ $text }}</span>@else{{ $text }}@endif</{{ $tag }}>@endif</td>
