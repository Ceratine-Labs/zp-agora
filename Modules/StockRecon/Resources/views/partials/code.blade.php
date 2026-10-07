{{--
    One stock recon code as a badge, in its own colour, that opens the codes
    modal at its explanation (Ryan, 7 Oct 2026: "when a user clicks on a
    badge it shows the modal"). The tone and the name come from
    config('stockrecon.exceptions') or config('stockrecon.audit') — the same
    vocabulary the modal, the filters and the procedure use.

    Props: $code (string), optional $title (what the hover adds, e.g. what
    PumpIT holds now).
--}}
@php
    $entry = config('stockrecon.exceptions.'.$code) ?? config('stockrecon.audit.'.$code);
    $tone = $entry['tone'] ?? 'neutral';
@endphp
<x-chip as="button" :tone="$tone" :dot="false" class="chip-code"
        data-modal-open="stockrecon-codes" data-modal-anchor="{{ $code }}"
        title="{{ $code }} — {{ $entry['label'] ?? 'what this code means' }}{{ isset($title) && $title ? ' · '.$title : '' }}. Click for what it means.">{{ $code }}</x-chip>
