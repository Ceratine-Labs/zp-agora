{{--
    A strip of tabs — the mockup's `tabs`.

    Two modes, and which one you want is decided by whether the tab changes the
    URL:

      * **Link mode.** Give each item an `href` and the component renders
        anchors. The server decides what is on screen, the browser handles the
        navigation, and a tab is a place you can send someone. No JavaScript is
        involved at all. This is the right mode whenever the panes are separate
        result sets — a link that carries its scope is the rule everywhere else
        in Agora and tabs are no exception.

      * **Panel mode.** Leave `href` out and put <x-tab-panel> children in the
        slot. The panes are rendered server-side with the inactive ones already
        `hidden`, so the correct one is on screen before any script runs;
        tabs.js only swaps the attribute. `persist` gives the set a key and the
        person's last choice is remembered on this browser — which is the only
        behaviour here that a native control cannot supply, and the only reason
        the module exists.

    `items` is a list of ['key' =>, 'label' =>, 'href' =>, 'count' =>].
--}}
@props([
    'items' => [],
    'active' => null,
    'persist' => null,
    'label' => 'Sections',
])

@php
    $items = collect($items)->map(fn ($item) => is_array($item) ? $item : ['key' => $item, 'label' => $item])->all();
    $active ??= data_get($items, '0.key');
    $linked = (bool) data_get($items, '0.href');
    $id = 'tabs-'.\Illuminate\Support\Str::random(6);
@endphp

<div {{ $attributes->merge(['class' => 'tabset']) }}
     @unless ($linked) data-tabs @endunless
     @if ($persist) data-tabs-persist="{{ $persist }}" @endif>

    <div class="tabs" role="{{ $linked ? 'group' : 'tablist' }}" aria-label="{{ $label }}">
        @foreach ($items as $item)
            @php($key = $item['key'])
            @php($on = (string) $key === (string) $active)

            @if ($linked)
                <a href="{{ $item['href'] }}" class="{{ $on ? 'on' : '' }}"
                   @if ($on) aria-current="page" @endif>
                    {{ $item['label'] }}
                    @isset($item['count'])<span class="ct">{{ $item['count'] }}</span>@endisset
                </a>
            @else
                <button type="button" role="tab" class="{{ $on ? 'on' : '' }}"
                        id="{{ $id }}-{{ $loop->index }}"
                        data-tab="{{ $key }}"
                        aria-selected="{{ $on ? 'true' : 'false' }}"
                        tabindex="{{ $on ? '0' : '-1' }}">
                    {{ $item['label'] }}
                    @isset($item['count'])<span class="ct">{{ $item['count'] }}</span>@endisset
                </button>
            @endif
        @endforeach
    </div>

    {{ $slot }}
</div>
