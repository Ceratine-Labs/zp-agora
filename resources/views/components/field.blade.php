{{--
    One labelled control, with its explanation attached.

    The `help` line is not decoration here. Every parameter on the recon
    workbench exists because the customer's configuration is ambiguous
    somewhere and we would not guess on their behalf; a control whose meaning
    lives only in a spec is a control someone will set wrongly.

    `choices` renders a select, `type` renders an input. A plain <select> is
    left plain — the native control beats a library on a phone, which is why
    select.js only claims `select[data-select]`.
--}}
@props([
    'name',
    'label' => '',
    'help' => null,
    'type' => 'text',
    'choices' => null,
    'value' => null,
    'min' => null,
    'max' => null,
    'placeholder' => null,
])

@php($id = 'f-'.str_replace(['[', ']', '.'], '-', $name))

<div {{ $attributes->merge(['class' => 'field']) }}>
    <label for="{{ $id }}">{{ $label }}</label>

    @if ($type === 'bool')
        <label class="field-check">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked($value)>
            <span>{{ $placeholder ?? 'On' }}</span>
        </label>
    @elseif ($choices)
        <select id="{{ $id }}" name="{{ $name }}">
            @foreach ($choices as $key => $text)
                <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $text }}</option>
            @endforeach
        </select>
    @else
        <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
               value="{{ $value }}"
               @if ($min !== null) min="{{ $min }}" @endif
               @if ($max !== null) max="{{ $max }}" @endif
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif>
    @endif

    @if ($help)<p class="field-help">{{ $help }}</p>@endif
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</div>
