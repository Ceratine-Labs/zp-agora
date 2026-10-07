{{--
    What the codes mean — one modal, in plain words, for the people at the
    sites (Ryan, 7 Oct 2026: they "dont know the 'client weekly pack' off by
    heart ... we can explain the codes"). Opened from the "i" on the
    exceptions and from any code badge (partials/code), which lands on that
    code's entry.

    Every word comes from config('stockrecon.exceptions') and
    config('stockrecon.audit'): `plain` what it means, `todo` what to do,
    `who` who does it — the client's own wording where its weekly pack
    defines the code. Nothing is written here twice.
--}}
@php
    $exceptions = (array) config('stockrecon.exceptions');
    $audit = (array) config('stockrecon.audit');

    $sections = [
        ['Between two shifts', 'The opening of a shift should be what the shift before it counted at its close.', ['S10a', 'S10b']],
        ['Stock with no issue sheet', 'More was sold or held than was ever issued: the stock was there, the paperwork was not.', ['S1', 'U1', 'U2', 'U3', 'U4']],
        ['An issue keyed twice', 'The same issue captured on two shifts next to each other.', ['D2', 'D1']],
        ['System and data faults', 'Not a loss: something in the data is wrong, and nobody is charged for it.', ['E1', 'F1', 'F2', 'F3']],
        ['Whole packs', 'Items are issued in packs; a pack-sized difference usually means a pack.', ['S3', 'S5']],
        ['Counting, and real shorts', 'How the item was counted, and what is left after balancing.', ['N1', 'N2', 'L1']],
    ];

    // What happens to the item's chain on a run, in a few words.
    $effect = function (string $code, array $e): array {
        if (in_array($code, ['S10a', 'S10b'], true)) {
            return ['Repaired', 'good'];
        }
        if (isset($e['balanced'])) {
            return [ucfirst(preg_replace('/^No — /u', '', $e['balanced'])), 'serious'];
        }

        return ($e['blocks'] ?? false) ? ['Held back', 'serious'] : ['Balanced', 'good'];
    };
@endphp

<x-modal id="stockrecon-codes" title="What the codes mean" wide>
    <div class="codes">
        <p>
            Every line on the exceptions carries one code — the most serious it trips. Click any code on the
            screen to come straight to it here. The colour says how much it matters:
        </p>
        <ul class="codes-tones">
            <li><x-chip tone="crit" :dot="false">Red</x-chip> stock went missing, or arrived with no issue sheet</li>
            <li><x-chip tone="serious" :dot="false">Orange</x-chip> needs a person before anything is written</li>
            <li><x-chip tone="warn" :dot="false">Amber</x-chip> check it</li>
            <li><x-chip tone="good" :dot="false">Green</x-chip> fixed by Agora</li>
            <li><x-chip tone="neutral" :dot="false">Grey</x-chip> for information</li>
        </ul>
        <p class="codes-note">
            <strong>Held back</strong> means Agora will not balance that item until the problem is fixed — unless the
            item's chain adds up to nil, in which case it always balances and the code is only reported.
            A difference of 3 or fewer between a closing and the next opening does not stop the chain; it shows in
            the <strong>Break</strong> column.
        </p>

        @foreach ($sections as [$heading, $intro, $codes])
            <section class="codes-section">
                <h3>{{ $heading }}</h3>
                <p class="codes-intro">{{ $intro }}</p>
                @foreach ($codes as $code)
                    @continue(! isset($exceptions[$code]))
                    @php($e = $exceptions[$code])
                    @php([$fx, $fxTone] = $effect($code, $e))
                    <article class="code-entry" id="stockrecon-codes-{{ $code }}">
                        <header>
                            <x-chip :tone="$e['tone']" :dot="false" class="chip-code">{{ $code }}</x-chip>
                            <strong>{{ $e['label'] }}</strong>
                            <span class="code-effect tone-{{ $fxTone }}">{{ $fx }}</span>
                        </header>
                        <dl>
                            <dt>What it means</dt><dd>{{ $e['plain'] ?? $e['blurb'] }}</dd>
                            <dt>What to do</dt><dd>{{ $e['todo'] ?? '—' }}</dd>
                            <dt>Who</dt><dd>{{ $e['who'] ?? '—' }}</dd>
                        </dl>
                    </article>
                @endforeach
            </section>
        @endforeach

        <section class="codes-section">
            <h3>Balancing already done by hand in PumpIT — the Audit column</h3>
            <p class="codes-intro">
                What somebody already changed when they balanced the recon by hand, checked against the rules every
                site works to. When Agora balances the item, it puts these changes back to what the staff counted.
            </p>
            @foreach ($audit as $code => $e)
                <article class="code-entry" id="stockrecon-codes-{{ $code }}">
                    <header>
                        <x-chip :tone="$e['tone']" :dot="false" class="chip-code">{{ $code }}</x-chip>
                        <strong>{{ $e['label'] }}</strong>
                    </header>
                    <dl>
                        <dt>What it means</dt><dd>{{ $e['plain'] ?? $e['blurb'] }}</dd>
                        <dt>What to do</dt><dd>{{ $e['todo'] ?? '—' }}</dd>
                        <dt>Who</dt><dd>{{ $e['who'] ?? '—' }}</dd>
                    </dl>
                </article>
            @endforeach
        </section>
    </div>
</x-modal>
