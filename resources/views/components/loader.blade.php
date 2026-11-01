{{--
    The wait, drawn as a cup of coffee — ZP's ask through Ryan, 23 Sep 2026 —
    or, on the stock recon screens, as a shopping cart being loaded with
    cartons (`variant="cart"`, ZP's ask, 7 Oct 2026).

    The cup fills, the ZRP badge sits on its face, steam rises once it is full,
    and it drains and fills again until the thing being waited for finishes.
    It replaces the busy states on the screens where people wait: a preview,
    a recon centre tab loading, a match or an execute, the bulk runners.

    ONE INSTANCE PER PAGE. <x-app-shell> renders it once as the `overlay`,
    showing during page initialization; loader.js reuses it for navigation and clones its art for a wait
    inside a pane. Nothing on a screen writes a second one — a screen asks
    window.Agora.loader, or puts `data-loader="What is happening…"` on a form.

    A wait under about 300 ms never shows it (loader.js): a cup that flashes on
    every quick press is noise, and teaches people to stop looking at it.

    Reduced motion gets a full, still cup and the words — the words are the
    part that carries the meaning; the animation only says "still going".

    The ids are per instance because clipPath is addressed by id, and a clone
    that pointed at another instance's clip would draw nothing the moment that
    one was hidden. loader.js renames them again on every clone.
--}}
@props([
    'label' => 'Working…',
    'overlay' => false,
    'startup' => false,
    // Shares its artwork with the module's menu tile. The app shell chooses
    // the route's variant from config('agora.loaders').
    'variant' => 'cup',
    'tool' => null,
    'report' => null,
    // On the overlay only: show it from the first paint, saying this, until
    // the page's held tables are ready (loader.js). Null: hidden as usual.
    'hold' => null,
])

<div {{ $attributes->merge(['class' => 'loader'.($overlay ? ' loader-overlay' : '')]) }}
     @if ($overlay) data-loader-overlay @if ($startup) data-loader-startup @endif @if ($hold) data-loader-hold @elseif (!$startup) hidden @endif @endif
     role="status" aria-live="polite">
    <div class="loader-box">
        @if ($tool)
            <x-tool-art :variant="$tool" animated />
        @elseif ($report)
            <x-report-art :variant="$report" class="module-art loader-art loader-{{ $report === 'my-queue' ? 'my-queue' : 'reports' }}"
                          data-module-art="{{ $report === 'my-queue' ? 'my-queue' : 'reports' }}" data-module-art-animated="true" />
        @else
            <x-module-art :variant="$variant" animated />
        @endif
        <p class="loader-label" data-loader-label>{{ $hold ?: $label }}</p>
    </div>
</div>
