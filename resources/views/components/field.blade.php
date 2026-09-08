{{--
    One labelled control, with its explanation attached.

    The `help` line is not decoration here. Every parameter on the recon
    workbench exists because the customer's configuration is ambiguous
    somewhere and we would not guess on their behalf; a control whose meaning
    lives only in a spec is a control someone will set wrongly.

    `choices` renders a select, `type` renders an input. A plain <select> is
    left plain — the native control beats a library on a phone, which is why
    select.js only claims `select[data-select]`.

    `choices` is normally a `[value => label]` map. It may instead be a LIST of
    `['value' =>, 'label' =>, 'when' =>]` rows, and that form exists for one
    reason: a keyed map cannot hold two options with the same value. Counting
    areas are numbered per site — area 11 exists at a dozen branches — so the
    stock recon centre renders every site's areas at once and lets
    `linked-select.js` narrow them. `when` is the controlling value each option
    belongs to; `linked` names the control it follows.

    `step` exists because `min` and `max` alone are a trap on a decimal. A
    number input's step defaults to 1, so a control declared min="0.01"
    max="1.0" holding the value 1 is INVALID to the browser — the valid values
    are 0.01, 1.01, 2.01 — and Chrome then refuses to submit the form with
    "an invalid form control is not focusable" and no visible message at all,
    because the field is inside a closed <details>. Caught on the stock recon
    caps, 8 September 2026: the Preview button simply did nothing.
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
    'step' => null,
    'linked' => null,
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
        <select id="{{ $id }}" name="{{ $name }}"
                @if ($linked) data-linked-select="{{ $linked }}" @endif>
            @foreach ($choices as $key => $text)
                @if (is_array($text))
                    {{-- The list form: the value is inside the row, because two
                         rows may legitimately carry the same one. --}}
                    <option value="{{ $text['value'] }}"
                            @isset($text['when']) data-when="{{ $text['when'] }}" @endisset
                            @selected((string) $value === (string) $text['value'])>{{ $text['label'] }}</option>
                @else
                    <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $text }}</option>
                @endif
            @endforeach
        </select>
    @else
        <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
               value="{{ $value }}"
               @if ($min !== null) min="{{ $min }}" @endif
               @if ($max !== null) max="{{ $max }}" @endif
               @if ($step !== null) step="{{ $step }}" @endif
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif>
    @endif

    @if ($help)<p class="field-help">{{ $help }}</p>@endif
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</div>
