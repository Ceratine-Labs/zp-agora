{{--
    One control inside <x-params> — the mockup's `params .f`, and `.f.dis` when
    the report does not take this parameter.

    `disabled` is the interesting state and it is why this is a component. A
    report library where every screen shows the same eight filters, greyed
    where they do not apply, tells the reader which parameters exist and which
    this particular report ignores. Hiding them instead makes every report look
    like a different application.

    `choices` renders a <select>, anything else renders an <input> of `type`.
    A plain select is left plain — the native control beats a library on a
    phone, which is why select.js only claims `select[data-select]`. Pass
    `searchable` on a long list to opt into TomSelect.

    `slot` overrides the control entirely, for the cases the props do not
    cover: a date range pair, a branch multi-select, a component of a module's
    own.
--}}
@props([
    'name' => null,
    'label' => '',
    'type' => 'text',
    'choices' => null,
    'value' => null,
    'disabled' => false,
    'searchable' => false,
    'placeholder' => null,
    'min' => null,
    'max' => null,
    'step' => null,
    'help' => null,
])

@php($id = $name ? 'p-'.str_replace(['[', ']', '.'], '-', $name) : null)

<div {{ $attributes->merge(['class' => 'f'.($disabled ? ' dis' : '')]) }}>
    <label @if ($id) for="{{ $id }}" @endif>{{ $label }}</label>

    @if ($slot->isNotEmpty())
        {{ $slot }}
    @elseif ($choices !== null)
        <select id="{{ $id }}" name="{{ $name }}"
                @disabled($disabled)
                @if ($searchable) data-select @endif
                @if ($placeholder) data-placeholder="{{ $placeholder }}" @endif>
            @foreach ($choices as $key => $text)
                <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $text }}</option>
            @endforeach
        </select>
    @else
        <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}"
               @disabled($disabled)
               @if ($min !== null) min="{{ $min }}" @endif
               @if ($max !== null) max="{{ $max }}" @endif
               @if ($step !== null) step="{{ $step }}" @endif
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif>
    @endif

    @if ($help)<span class="f-help">{{ $help }}</span>@endif

    {{-- $errors is shared by the web middleware, so it is absent when the
         component is rendered outside a request — a gallery, or a test. @error
         reads it unguarded and fatals, which is a component that only works in
         one of the two places it is used. --}}
    @if ($name && isset($errors))
        @error($name)<span class="f-error">{{ $message }}</span>@enderror
    @endif
</div>
