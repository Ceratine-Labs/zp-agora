{{-- The chain read back in plain words (Ryan, 15 Nov 2026), from
     Modules\StockRecon\Services\ChainExplainer. Props: $html (rendered
     markdown, html stripped) or $error. --}}
@if ($error)
    <x-notice tone="warn" title="No explanation"><p>{{ $error }}</p></x-notice>
@else
    <div class="chain-explain-text">{!! $html !!}</div>
    <p class="muted chain-explain-src">Read by Claude from this chain's figures. Check it against the grid before acting on it.</p>
@endif
