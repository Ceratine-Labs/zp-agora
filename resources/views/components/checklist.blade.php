{{--
    An ordered set of steps that has to be finished before something can be
    closed — the branch console's end-of-day capture list.

    An <ol>, because the order is real: the Z-reads cannot be allocated before
    the POS files are imported. Screen readers get "3 of 7" for free from the
    list semantics, which a stack of divs would not give them.

    Each step is ['title' =>, 'detail' =>, 'done' =>, 'href' =>]. `done` drives
    both the mark and the chip; the chip is there because the mark alone is a
    green circle and a green circle is not a status a colour-blind reader can
    name.

    A step with no `href` renders as text rather than a dead link
    (feature-rules §3.7: a reference that leads nowhere is not a link).
--}}
@props(['steps' => [], 'empty' => 'No steps for this day.'])

@php($done = collect($steps)->where('done', true)->count())

<ol {{ $attributes->merge(['class' => 'checklist']) }}
    aria-label="{{ $done }} of {{ count($steps) }} complete">
    @forelse ($steps as $index => $step)
        @php($ok = (bool) ($step['done'] ?? false))
        <li class="step {{ $ok ? 'is-done' : 'is-open' }}">
            @php($tag = ! empty($step['href']) ? 'a' : 'div')
            <{{ $tag }} class="step-in" @if (! empty($step['href'])) href="{{ $step['href'] }}" @endif>
                <span class="step-mark" aria-hidden="true">{{ $ok ? '✓' : $index + 1 }}</span>
                <span class="step-text">
                    <span class="t">{{ $step['title'] ?? '' }}</span>
                    <span class="asat">{{ $step['detail'] ?? '' }}</span>
                </span>
                <x-chip :tone="$ok ? 'good' : 'warn'">{{ $ok ? 'Done' : 'Outstanding' }}</x-chip>
            </{{ $tag }}>
        </li>
    @empty
        <li class="step"><x-empty-state :text="$empty" /></li>
    @endforelse
</ol>
