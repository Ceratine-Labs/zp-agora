{{--
    Head office / Branch — the mockup's `wsw`, lifted out of the app bar.

    It was inline in <x-app-bar>, which meant the sign-in screen, the
    styleguide and the component gallery could not show it and no test could
    render it on its own. It is one control with one job and it belongs in the
    library like everything else.

    Anchors rather than buttons, because switching workspace is a navigation:
    it changes what the whole application is about, the choice belongs in the
    URL so a link carries it, and the browser's back button then does the
    obvious thing. `fullUrlWithQuery` keeps whatever branch and date the person
    was already looking at.

    `param` is the query key. `workspaces` is code => label.
--}}
@props([
    'workspaces' => [],
    'current' => null,
    'param' => 'ws',
    'label' => 'Workspace',
])

<div {{ $attributes->merge(['class' => 'wsw']) }} role="group" aria-label="{{ $label }}">
    @foreach ($workspaces as $code => $text)
        @php($on = (string) $current === (string) $code)
        <a href="{{ request()->fullUrlWithQuery([$param => $code]) }}"
           class="{{ $on ? 'on' : '' }}"
           @if ($on) aria-current="true" @endif>{{ $text }}</a>
    @endforeach
</div>
