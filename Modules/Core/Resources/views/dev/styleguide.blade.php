<!doctype html>
<html lang="en" @if(request()->cookie('agora_theme')) data-theme="{{ request()->cookie('agora_theme') }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Theme · Agora</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>

<header class="appbar">
    <a class="brand" href="{{ url('/app') }}">
        <span class="brand-text">
            <span class="wm">AG<em>O</em>RA</span>
            <span class="sub">Styleguide</span>
        </span>
    </a>
    <nav class="primary"></nav>
    <div class="tools">
        <button class="iconbtn" id="themebtn" aria-label="Switch light / dark" title="Switch light / dark">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.2 9.6A5.6 5.6 0 0 1 6.4 2.8a5.6 5.6 0 1 0 6.8 6.8Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        </button>
    </div>
</header>

<main class="shell-main">
    <div class="shell-in">
        <x-page-head
            eyebrow="Development"
            title="Theme"
            blurb="Every token, the type scale, and the number formats — rendered rather than described. Use the toggle in the bar to check both themes." />

        <x-card title="Colour tokens" sub="Defined once in resources/scss/_tokens.scss. No component may declare a colour of its own.">
            @foreach ($tokens as $group => $names)
                <h3 class="sg-group">{{ $group }}</h3>
                <div class="sg-swatches">
                    @foreach ($names as $name)
                        <div class="sg-swatch">
                            <span class="sg-chip" style="background: var(--{{ $name }})"></span>
                            <code>--{{ $name }}</code>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </x-card>

        <x-card title="Type" sub="Barlow Condensed for display, IBM Plex Sans for text, IBM Plex Mono for codes and figures — all served from Agora, not from a font host.">
            <div class="sg-type">
                <p class="sg-spec">Display · Barlow Condensed 600 · 30px</p>
                <h1 style="font-family: var(--font-display); font-size: 30px;">Weekly Exco trading pack</h1>

                <p class="sg-spec">Display · 18px</p>
                <h2 style="font-family: var(--font-display); font-size: 18px;">Cash and banking exceptions</h2>

                <p class="sg-spec">Body · IBM Plex Sans 400 · 13.5px</p>
                <p style="max-width: 65ch;">The dip must tie to the pump, the Z-read to the cash-up, the declaration to the bank. Every figure on a screen can be opened to the load it came from.</p>

                <p class="sg-spec">Body · 600</p>
                <p style="font-weight: 600;">Ngwelezane Convenience Centre — day not closed</p>

                <p class="sg-spec">Mono · IBM Plex Mono 400 · codes, times and identifiers</p>
                <p style="font-family: var(--font-mono);">BRN_DailyBanking · 2026-09-04 05:13 · ULP 95</p>

                <p class="sg-spec">Uppercase label · 10.5px · .12em</p>
                <p class="eyebrow">Trading sites</p>
            </div>
        </x-card>

        <x-card title="Components" sub="Each one is listed in docs/components.md. Build the component before the screen.">
            <div class="kpi-strip">
                <x-kpi label="Fuel volume" value="847k L" note="7 days" />
                <x-kpi label="Shop turnover" value="R2.21m" note="against R2.08m" tone="good" />
                <x-kpi label="Unallocated Z-reads" value="12" note="oldest 4 days" tone="warn" />
                <x-kpi label="Bank unmatched" value="R91k" note="31 lines" tone="crit" />
            </div>
            <p>
                <x-chip>Neutral</x-chip>
                <x-chip tone="good">Reconciled</x-chip>
                <x-chip tone="warn">Review</x-chip>
                <x-chip tone="serious">Escalated</x-chip>
                <x-chip tone="crit">Not banked</x-chip>
            </p>
            <p><button type="button" class="btn-primary" onclick="window.Agora.notify.toast('Nothing was changed — this is the styleguide.')">Show a toast</button></p>
            <x-empty-state text="Nothing to show — this is what an empty result looks like." />
        </x-card>

        <x-card title="Numbers" sub="The PHP helper and its JavaScript twin, on the same inputs. The two columns must match exactly.">
            <p>These are the same figures rendered on the server and in the browser. They are shown side by side because the locales disagree — asked for en-ZA, PHP returns <code>1,234,567.89</code> and JavaScript returns <code>1&nbsp;234&nbsp;567,89</code> — so Agora states the format itself rather than trusting either.</p>
            <div class="table-wrap">
                <table id="parity">
                    <thead>
                        <tr><th>Call</th><th>PHP</th><th>JavaScript</th><th class="num">Match</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr data-fn="{{ $case['fn'] }}" data-args='@json($case['args'])'>
                                <td><code>{{ $case['fn'] }}({{ collect($case['args'])->map(fn ($a) => is_null($a) ? 'null' : $a)->implode(', ') }})</code></td>
                                <td class="php-out">{{ $case['php'] }}</td>
                                <td class="js-out"></td>
                                <td class="num verdict"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</main>

<script type="module">
    import * as format from '{{ Vite::asset('resources/js/format.js') }}';

    document.querySelectorAll('#parity tbody tr').forEach((row) => {
        const fn = row.dataset.fn;
        const args = JSON.parse(row.dataset.args);
        const js = format[fn](...args);

        row.querySelector('.js-out').textContent = js;

        const php = row.querySelector('.php-out').textContent;
        const same = php === js;
        const cell = row.querySelector('.verdict');
        cell.textContent = same ? 'yes' : 'NO';
        cell.className = 'num verdict ' + (same ? 'ok' : 'bad');
    });
</script>

</body>
</html>
