{{--
    The support modal's body (Ryan, 16 Nov 2026): the chain the request is
    about, filled in, a message, and an optional screenshot the person can mark
    up with a pen, a circle or an arrow before it is sent. The behaviour is
    resources/js/components/support-request.js; without script the form still
    posts the message (and a chosen image) and the page says it was logged.

    Props: $run, $line (any shift of the chain), $from, $to, $shifts
--}}
@php($day = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('j M Y'))
<form method="POST" action="{{ route('app.stockrecon.support.store', [$run, $line]) }}"
      enctype="multipart/form-data" class="support-form" data-support-form>
    @csrf
    <x-facts :columns="4">
        <x-fact label="Item" :value="$line->ItemDescription ?? $line->StockItemNo">{{ $line->StockItemNo }}</x-fact>
        <x-fact label="Area" :value="$line->AreaDescription ?? 'Area '.$line->AreaNo" />
        <x-fact label="Run" :value="'#'.$run->Id">{{ $run->FromDate->format('j M') }} to {{ $run->ToDate->format('j M Y') }}</x-fact>
        <x-fact label="Chain" :value="\Illuminate\Support\Carbon::parse($from)->format('j M').' – '.$day($to)">{{ $shifts }} {{ Str::plural('shift', $shifts) }}</x-fact>
    </x-facts>

    <div class="support-field">
        <label for="support-message">What is wrong with this chain?</label>
        <textarea id="support-message" name="message" rows="4" required minlength="5" maxlength="2000"
                  placeholder="For example: the 10 Nov afternoon closing was counted but Agora shows 0."></textarea>
    </div>

    <div class="support-shot" data-support-shot>
        <div class="support-shot-head">
            <span>Screenshot <span class="muted">(optional)</span></span>
            <div class="support-shot-actions">
                <button type="button" class="btn-ghost btn-row" data-shot-capture>Capture the screen</button>
                <label class="btn-ghost btn-row support-shot-file">Choose an image
                    <input type="file" name="screenshot" accept="image/png,image/jpeg,image/webp" data-shot-file>
                </label>
            </div>
        </div>
        <p class="muted support-shot-hint" data-shot-hint>Capture this screen, paste an image (Ctrl+V) or choose one, then mark it up.</p>
        <div class="support-shot-tools" data-shot-tools hidden>
            <button type="button" class="btn-ghost btn-row is-on" data-shot-tool="pen" aria-pressed="true">Pen</button>
            <button type="button" class="btn-ghost btn-row" data-shot-tool="circle" aria-pressed="false">Circle</button>
            <button type="button" class="btn-ghost btn-row" data-shot-tool="arrow" aria-pressed="false">Arrow</button>
            <button type="button" class="btn-ghost btn-row" data-shot-undo>Undo</button>
            <button type="button" class="btn-ghost btn-row" data-shot-clear>Remove</button>
        </div>
        <canvas class="support-shot-canvas" data-shot-canvas hidden></canvas>
    </div>

    <p class="support-status" data-support-status role="status" aria-live="polite"></p>

    <div class="support-form-actions">
        <button type="submit" class="btn-primary" data-support-send>Send to support</button>
    </div>
</form>
