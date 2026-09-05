{{--
    The left-hand column of the sign-in page: who this is, what the name means,
    and what the system is doing right now.

    The wordmark, expansion, tagline and paragraph come from
    Modules/Core/Resources/lang/en/core.php rather than being written here —
    they are the same four strings the mockup carries in PIT.brand, and a
    second copy in a blade file is a second copy to keep right.

    `state` is a list of App\Support\Badges\Badge. The component draws them; it
    does not know where a figure comes from and cannot count anything itself
    (docs/components.md: a component never queries the database).
--}}
@props(['state' => []])

<div class="signin-mark">
    {{-- AG<em>O</em>RA: the O is the sand-coloured one, as in the mockup. --}}
    <p class="wm">{!! __('core::core.brand.wordmark_html') !!}</p>
    <p class="sub">{{ __('core::core.brand.owner') }}</p>
</div>

<p class="signin-exp">{{ __('core::core.brand.expansion') }}</p>
<p class="signin-tag">{{ __('core::core.brand.tagline') }}</p>
<p class="signin-why">{{ __('core::core.brand.why') }}</p>

@if ($state)
    <div class="signin-state">
        {{--
            Visible before anybody signs in, which is the mockup's design and a
            real decision: the first question at 06:00 is whether last night's
            loads ran, and making somebody sign in to find out costs a minute
            every morning across 31 sites.
        --}}
        <p class="eyebrow">{{ __('core::core.signin.system_state') }}</p>

        @foreach ($state as $badge)
            <div class="signin-state-row">
                <span class="sdot {{ $badge->tone }}" aria-hidden="true"></span>
                @if ($badge->route && \Illuminate\Support\Facades\Route::has($badge->route))
                    <a href="{{ route($badge->route) }}">{{ $badge->label }}</a>
                @else
                    <span>{{ $badge->label }}</span>
                @endif
            </div>
        @endforeach
    </div>
@endif
