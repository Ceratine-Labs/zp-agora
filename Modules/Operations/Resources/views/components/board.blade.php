@props(['endpoint', 'userKey', 'sites' => [], 'scope' => []])
@php
    $wallScreens = ['overview' => 'Overview', 'fuel' => 'Fuel', 'shop' => 'Shop', 'qsr' => 'QSR', 'stock' => 'Stock', 'cashups' => 'Cash-ups'];
@endphp
<section class="operations-wall" data-operations-wall
    data-wall-config="{{ json_encode(['endpoint' => $endpoint, 'userKey' => $userKey, 'sites' => $sites, 'scope' => $scope]) }}"
    aria-label="Operations wall">
    <header class="operations-wall__bar">
        <div class="operations-wall__brand">
            <x-feature-art class="operations-wall__title-art" :art="app(\Modules\Core\Services\NavigationArtwork::class)->forRoute('app.operations.wall')" />
            <div><span class="operations-wall__eyebrow">Zululand Retail &amp; Petroleum</span><h1>Operations wall</h1></div>
        </div>
        <div class="operations-wall__controls">
            <button type="button" class="btn-ghost" data-wall-action="settings">TV settings</button>
            <button type="button" class="btn-ghost" data-wall-action="refresh">Refresh</button>
            <button type="button" class="btn-ghost" data-wall-action="fullscreen">Full screen</button>
            <button type="button" class="btn-primary operations-wall__pause" data-wall-action="pause" aria-pressed="false">Pause rotation</button>
        </div>
    </header>

    <div class="operations-wall__statusbar">
        <span class="operations-wall__connection" data-wall-connection role="status">Connecting to live sources…</span>
        <span data-wall-scope>Loading site scope</span>
        <time class="operations-wall__clock" data-wall-clock></time>
    </div>
    <p class="operations-wall__error" data-wall-error role="alert" hidden></p>
    <nav class="operations-wall__nav" data-wall-nav aria-label="Wall screens"></nav>
    <div class="operations-wall__progress" aria-hidden="true"><span data-wall-progress></span></div>

    <div class="operations-wall__stage" data-wall-stage aria-busy="true">
        <div class="operations-wall__heading">
            <div>
                <span class="operations-wall__eyebrow" data-wall-date>Today's operations</span>
                <h2 data-wall-title>Bringing the day into view</h2>
                <p data-wall-subtitle>Reading the latest available figures for your sites.</p>
            </div>
            <div class="operations-wall__rotation">
                <strong data-wall-rotation>25 second rotation</strong>
                <span data-wall-next>Fuel · Shop · QSR · Stock · Cash-ups</span>
            </div>
        </div>
        <div class="operations-wall__metrics" data-wall-metrics></div>
        <div class="operations-wall__list-head">
            <div><h3 data-wall-list-title>Attention first</h3><span data-wall-count>Loading…</span></div>
            <div>
                <label class="operations-wall__check"><input type="checkbox" data-wall-attention> Attention only</label>
                <label class="operations-wall__check"><input type="checkbox" data-wall-hide-zero> Hide zero values</label>
            </div>
        </div>
        <div class="operations-wall__cards" data-wall-cards></div>
        <div class="operations-wall__empty" data-wall-empty hidden></div>
        <div class="operations-wall__paging" data-wall-paging>
            <span data-wall-page></span>
            <div>
                <button type="button" class="btn-ghost" data-wall-action="page-prev" aria-label="Previous alerts">Previous</button>
                <button type="button" class="btn-ghost" data-wall-action="page-next" aria-label="Next alerts">Next</button>
                <button type="button" class="btn-ghost" data-wall-action="all">View all</button>
            </div>
        </div>
        <aside class="operations-wall__coverage" aria-label="Data coverage">
            <div class="operations-wall__coverage-head"><strong>Source coverage</strong><span data-wall-source>Checking available sources</span></div>
            <div data-wall-gaps></div>
        </aside>
    </div>

    <footer class="operations-wall__foot">
        <span data-wall-updated>No snapshot received yet</span>
        <span>Refreshes every minute · All times SAST</span>
        <span class="operations-wall__key-hint">Space to pause · ← → to change screen · Click any card for detail</span>
    </footer>

    <dialog class="operations-wall__settings" data-wall-settings aria-labelledby="wall-settings-title">
        <form data-wall-settings-form>
            <header class="operations-wall__dialog-head">
                <div><span class="operations-wall__eyebrow">Saved on this TV for your account</span><h2 id="wall-settings-title">Make this wall yours</h2></div>
                <button type="button" class="btn-ghost" data-wall-action="settings-close" aria-label="Close TV settings">Close</button>
            </header>
            <div class="operations-wall__settings-body">
                <div class="field">
                    <label for="wall-sites">Sites</label>
                    <select id="wall-sites" name="branches[]" multiple data-select data-placeholder="All permitted sites">
                        @foreach ($sites as $site)
                            <option value="{{ data_get($site, 'id') }}">{{ data_get($site, 'name') }}</option>
                        @endforeach
                    </select>
                    <small>Choose the sites for this office. Leave empty for every site you are permitted to view.</small>
                </div>
                <fieldset class="operations-wall__screen-choices">
                    <legend>Screens in the rotation</legend>
                    @foreach ($wallScreens as $key => $label)
                        <label class="operations-wall__check"><input type="checkbox" name="screens[]" value="{{ $key }}" checked> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <div class="field">
                    <label for="wall-seconds">Time on each screen</label>
                    <select id="wall-seconds" name="seconds">
                        <option value="15">15 seconds</option>
                        <option value="25" selected>25 seconds</option>
                        <option value="40">40 seconds</option>
                        <option value="60">60 seconds</option>
                    </select>
                </div>
                <label class="operations-wall__check"><input type="checkbox" name="morning" checked> Prioritise cash-ups before noon</label>
                <label class="operations-wall__check"><input type="checkbox" name="autoReturn" checked> Return to rotation after 2 minutes without interaction</label>
                <p class="operations-wall__settings-note">An explicit pause stays paused until you press Resume. The wall shows missing feeds as gaps, never as a green result. Site and report access follow your Agora permissions.</p>
            </div>
            <footer class="operations-wall__dialog-foot"><button type="submit" class="btn-primary">Apply to this TV</button></footer>
        </form>
    </dialog>

    <dialog class="operations-wall__drawer" data-wall-drawer aria-labelledby="wall-detail-title">
        <header class="operations-wall__dialog-head">
            <div><span class="operations-wall__eyebrow" data-wall-detail-site>Operations detail</span><h2 id="wall-detail-title" data-wall-detail-title>Detail</h2></div>
            <button type="button" class="btn-ghost" data-wall-action="detail-close">Close</button>
        </header>
        <div class="operations-wall__drawer-body">
            <p class="operations-wall__drawer-note">Rotation is paused while you explore.</p>
            <div data-wall-detail-body></div>
        </div>
    </dialog>
    <noscript><p class="operations-wall__error">Enable JavaScript to show and refresh the Operations Wall. Existing Agora reports remain available from the menu.</p></noscript>
</section>
