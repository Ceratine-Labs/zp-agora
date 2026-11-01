/**
 * Read-only TV board. Source labels are always text nodes, never HTML.
 * Only preferences are stored; business figures never survive a signed-in page.
 */
const SCREEN_IDS = ['overview', 'fuel', 'shop', 'qsr', 'stock', 'cashups'];
const SEVERITY = { crit: 0, warn: 1, neutral: 2, good: 3 };
const PAGE_SIZE = 6;
const REFRESH_MS = 60000;
const RETURN_MS = 120000;
const tone = (value) => Object.hasOwn(SEVERITY, value) ? value : 'neutral';
const string = (value) => value == null ? '' : String(value);

export function wallPreferences(raw = {}, sites = []) {
    const ids = new Set(sites.map((site) => string(site.id)));
    const screens = Array.isArray(raw.screens) ? SCREEN_IDS.filter((id) => raw.screens.includes(id)) : SCREEN_IDS;
    const requested = Array.isArray(raw.branches) ? [...new Set(raw.branches.map(string))] : [];
    const branches = requested.filter((id) => ids.has(id));
    return {
        branches,
        // Losing every saved site must not silently turn a narrow TV into an
        // all-sites wall. An explicit Apply is required to choose a new scope.
        scopeInvalid: raw.scopeInvalid === true || (requested.length > 0 && branches.length === 0),
        screens: screens.length ? screens : ['overview'],
        seconds: [15, 25, 40, 60].includes(Number(raw.seconds)) ? Number(raw.seconds) : 25,
        autoReturn: raw.autoReturn !== false,
        morning: raw.morning !== false,
        attention: raw.attention === true,
        hideZero: raw.hideZero === true,
    };
}

export function wallOrder(screens, preferences, date = new Date()) {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Johannesburg', hour: '2-digit', hourCycle: 'h23' }).format(date));
    let ids = preferences.screens.filter((id) => screens.some((screen) => screen.id === id));
    if (preferences.morning && hour < 12 && ids.includes('cashups')) {
        ids = ids.filter((id) => id !== 'cashups');
        ids.splice(ids[0] === 'overview' ? 1 : 0, 0, 'cashups');
    }
    return ids.length ? ids : screens.slice(0, 1).map((screen) => screen.id);
}

export function wallItems(screen, attention = false, hideZero = false) {
    return (Array.isArray(screen?.items) ? screen.items : [])
        .filter((item) => !attention || ['crit', 'warn'].includes(item.severity))
        .filter((item) => !hideZero || item.isZero !== true)
        .slice().sort((a, b) => SEVERITY[tone(a.severity)] - SEVERITY[tone(b.severity)]);
}

export function wallNextPage(page, itemCount) {
    return (page + 1) % Math.max(1, Math.ceil(itemCount / PAGE_SIZE));
}

export function wallResponsePolicy(status, redirected = false) {
    const accessChanged = redirected || [401, 403, 419].includes(status);
    return { accessChanged, keepSnapshot: !accessChanged, retry: !accessChanged };
}

export function wallOverview(snapshot) {
    const screens = snapshot.screens.filter((screen) => screen.id !== 'overview');
    const items = screens.flatMap((screen) => (screen.items || []).map((item) => ({
        ...item, id: `${screen.id}:${item.id}`, category: screen.title, sourceLabel: screen.sourceLabel,
    })));
    const live = screens.filter((screen) => screen.status === 'live').length;
    return {
        id: 'overview', title: 'The day at a glance',
        subtitle: 'The most urgent signals across your sites. Open a card to follow the evidence.',
        status: live ? 'live' : 'unavailable', updatedAt: snapshot.generatedAt,
        sourceLabel: `${live} of ${screens.length} areas reporting`,
        message: live ? '' : 'No live area has returned data for this scope.',
        metrics: [
            { label: 'Critical signals', value: string(items.filter((item) => item.severity === 'crit').length), tone: items.some((item) => item.severity === 'crit') ? 'crit' : 'neutral' },
            { label: 'Needs review', value: string(items.filter((item) => item.severity === 'warn').length), tone: items.some((item) => item.severity === 'warn') ? 'warn' : 'neutral' },
            { label: 'Sites in scope', value: string(snapshot.scope?.siteCount ?? '—'), tone: 'neutral' },
            { label: 'Areas reporting', value: `${live} / ${screens.length}`, tone: live < screens.length ? 'warn' : 'neutral' },
        ],
        items,
        gaps: screens.flatMap((screen) => {
            const gaps = (screen.gaps || []).map((gap) => `${screen.title}: ${gap}`);
            if (screen.status !== 'live') gaps.unshift(`${screen.title}: ${screen.message || 'Source unavailable'}`);
            return gaps;
        }),
    };
}

export function wallSnapshotUrl(endpoint, preferences, base) {
    if (preferences.scopeInvalid) throw new Error('Choose sites in TV settings before refreshing this wall.');
    const url = new URL(endpoint, base);
    if (url.origin !== new URL(base).origin) throw new Error('The wall endpoint must belong to Agora.');
    url.searchParams.delete('branches[]');
    url.searchParams.delete('branches');
    preferences.branches.forEach((id) => url.searchParams.append('branches[]', id));
    url.searchParams.delete('division');
    return url;
}

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = string(text);
    return element;
}

function time(value, date = false) {
    const parsed = new Date(value);
    if (!value || Number.isNaN(parsed.getTime())) return 'Not supplied';
    return new Intl.DateTimeFormat('en-ZA', {
        timeZone: 'Africa/Johannesburg',
        ...(date ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
        hourCycle: 'h23',
    }).format(parsed);
}

function safeReportUrl(href) {
    if (!href) return null;
    try {
        const url = new URL(href, window.location.href);
        return url.origin === window.location.origin && url.pathname.startsWith('/app/') ? url.href : null;
    } catch { return null; }
}

export default function operationsWall(root = document) {
    const readiness = [];
    root.querySelectorAll('[data-operations-wall]:not([data-wall-ready])').forEach((wall) => {
        wall.dataset.wallReady = '';
        let config;
        try { config = JSON.parse(wall.dataset.wallConfig); } catch { return; }
        const find = (key) => wall.querySelector(`[data-wall-${key}]`);
        const settings = find('settings');
        const form = find('settings-form');
        const drawer = find('drawer');
        const storage = `agora.operations-wall.v1.${config.userKey}`;
        let saved = {};
        try { saved = JSON.parse(localStorage.getItem(storage) || '{}') || {}; } catch { /* Private mode uses defaults. */ }
        const pageUrl = new URL(window.location.href);
        const explicitScope = [...pageUrl.searchParams.keys()].some((key) => key === 'wall_scope' || key.startsWith('branches'));
        let preferences = wallPreferences({ ...saved, ...(explicitScope ? { ...config.scope, scopeInvalid: false } : {}) }, config.sites);
        let snapshot = null;
        let currentId = 'overview';
        let page = 0;
        let explicitPaused = false;
        let interactionPaused = false;
        let lastInteraction = Date.now();
        let lastAdvance = Date.now();
        let inFlight = false;
        let abort = null;
        let refreshTimer = null;
        let scopeVersion = 0;
        let refreshQueued = false;
        let error = preferences.scopeInvalid ? 'The sites saved for this TV are no longer available to your account. Open TV settings and choose sites before refreshing.' : '';
        let initial = true;
        let listOpen = false;
        let drawerItems = [];
        let suspended = false;
        let clockTimer = null;
        let accessChanged = false;
        const pageCursor = new Map();

        const save = () => { try { localStorage.setItem(storage, JSON.stringify(preferences)); } catch { /* TV storage may be blocked. */ } };
        const paused = () => explicitPaused || interactionPaused || settings.open || drawer.open || document.hidden;
        const screens = () => snapshot ? [wallOverview(snapshot), ...snapshot.screens.filter((screen) => screen.id !== 'overview')] : [];
        const order = () => wallOrder(screens(), preferences);
        const current = () => screens().find((screen) => screen.id === currentId);
        const touch = () => { lastInteraction = Date.now(); };

        function updateScopeUrl() {
            if (preferences.scopeInvalid) return;
            const url = new URL(window.location.href);
            [...url.searchParams.keys()].filter((key) => key.startsWith('branches') || key === 'division').forEach((key) => url.searchParams.delete(key));
            preferences.branches.forEach((id) => url.searchParams.append('branches[]', id));
            // Mark an explicit "all sites" URL so it does not inherit another TV's selection.
            url.searchParams.set('wall_scope', 'selected');
            window.history.replaceState(window.history.state, '', url);
        }

        function connection() {
            const age = snapshot ? Date.now() - new Date(snapshot.generatedAt).getTime() : Infinity;
            const stale = !!snapshot && (!Number.isFinite(age) || age > 180000);
            const status = find('connection');
            status.classList.toggle('is-warn', !!error || stale);
            status.textContent = error ? (snapshot ? 'Refresh failed · showing last snapshot' : 'Connection unavailable')
                : stale ? 'Snapshot is over 3 minutes old'
                    : snapshot ? 'Live connection' : 'Connecting to live sources…';
            find('error').hidden = !error;
            find('error').textContent = error;
            find('updated').textContent = snapshot ? `Snapshot ${time(snapshot.generatedAt)}${stale || error ? ' · may be out of date' : ''}` : 'No snapshot received yet';
        }

        function render() {
            connection();
            const all = screens();
            const ids = order();
            if (!ids.includes(currentId)) currentId = ids[0] || 'overview';
            const screen = all.find((entry) => entry.id === currentId);
            find('nav').replaceChildren(...ids.map((id) => {
                const entry = all.find((candidate) => candidate.id === id);
                const button = node('button', `operations-wall__tab${id === currentId ? ' is-current' : ''}`);
                button.type = 'button';
                button.dataset.wallScreen = id;
                button.setAttribute('aria-current', id === currentId ? 'page' : 'false');
                button.append(node('span', '', id === 'overview' ? 'Overview' : entry.title));
                const count = (entry.items || []).filter((item) => item.severity === 'crit').length;
                if (count) button.append(node('span', 'operations-wall__tab-count', count));
                return button;
            }));
            find('stage').setAttribute('aria-busy', snapshot ? 'false' : 'true');
            find('attention').checked = preferences.attention;
            find('hide-zero').checked = preferences.hideZero;
            if (!screen) {
                find('metrics').replaceChildren();
                find('cards').replaceChildren();
                find('empty').hidden = false;
                find('empty').textContent = error || 'Reading the latest available figures for your sites…';
                find('scope').textContent = 'Waiting for site scope';
                find('title').textContent = accessChanged ? 'Sign in to continue' : 'Bringing the day into view';
                find('subtitle').textContent = accessChanged ? 'Reload this page to refresh your session and access.' : 'Reading the latest available figures for your sites.';
                find('date').textContent = "Today's operations";
                find('source').textContent = 'Checking available sources';
                find('gaps').replaceChildren();
                find('count').textContent = '';
                find('paging').hidden = true;
                return;
            }
            find('scope').textContent = snapshot.scope?.label || `${snapshot.scope?.siteCount ?? 0} sites in scope`;
            find('date').textContent = `Business date ${snapshot.businessDate || 'not supplied'}`;
            find('title').textContent = screen.title;
            find('subtitle').textContent = screen.subtitle || screen.message || '';
            find('metrics').replaceChildren(...(screen.metrics || []).map((metric) => {
                const tile = node('div', `operations-wall__metric is-${tone(metric.tone)}`);
                tile.append(node('span', 'operations-wall__metric-label', metric.label), node('strong', '', metric.value ?? '—'));
                return tile;
            }));
            const items = wallItems(screen, preferences.attention, preferences.hideZero);
            page = Math.min(page, Math.max(0, Math.ceil(items.length / PAGE_SIZE) - 1));
            const first = page * PAGE_SIZE;
            find('cards').replaceChildren(...items.slice(first, first + PAGE_SIZE).map((item, index) => card(item, first + index)));
            find('list-title').textContent = preferences.attention ? 'Needs attention' : 'Attention first';
            find('count').textContent = `${items.length} ${items.length === 1 ? 'signal' : 'signals'}${preferences.attention ? ' needing attention' : ' in this view'}${preferences.hideZero ? ' · zero values hidden' : ''}`;
            find('empty').hidden = items.length !== 0;
            find('empty').textContent = screen.status !== 'live' ? screen.message || 'This source is not available yet. See coverage below.'
                : preferences.hideZero ? 'No signals match these filters. Uncheck Hide zero values to include zero-value signals.'
                : preferences.attention ? 'No critical or warning signals in the available data. Check source coverage below.'
                    : screen.message || 'No signals returned for this site scope. Check source coverage below.';
            find('page').textContent = items.length ? `Showing ${first + 1}–${Math.min(first + PAGE_SIZE, items.length)} of ${items.length}` : '';
            find('paging').hidden = false;
            wall.querySelector('[data-wall-action="page-prev"]').disabled = page === 0;
            wall.querySelector('[data-wall-action="page-next"]').disabled = first + PAGE_SIZE >= items.length;
            wall.querySelector('[data-wall-action="all"]').disabled = items.length === 0;
            find('source').textContent = `${screen.sourceLabel || 'Source not supplied'} · ${screen.updatedAt ? `Source as at ${time(screen.updatedAt, true)}` : 'Source timestamp not supplied'}`;
            const gaps = [...(screen.gaps || [])];
            if (screen.status !== 'live' && screen.message && !gaps.includes(screen.message)) gaps.unshift(screen.message);
            if (gaps.length) {
                const details = node('details', 'operations-wall__coverage-details');
                details.append(node('summary', '', `${gaps.length} ${gaps.length === 1 ? 'coverage gap' : 'coverage gaps'} · See what this view does not yet cover`),
                    ...gaps.map((gap) => node('p', 'operations-wall__gap', gap)));
                find('gaps').replaceChildren(details);
            } else find('gaps').replaceChildren(node('p', 'operations-wall__coverage-ok', 'No additional coverage gaps reported by this source.'));
            tick();
        }

        function card(item, index) {
            const button = node('button', `operations-wall__card is-${tone(item.severity)}`);
            button.type = 'button';
            button.dataset.wallItem = string(index);
            const head = node('span', 'operations-wall__card-head');
            head.append(node('span', 'operations-wall__severity', { crit: 'Critical', warn: 'Review', good: 'In range', neutral: 'Information' }[tone(item.severity)]));
            if (item.category) head.append(node('span', 'operations-wall__category', item.category));
            button.append(head, node('span', 'operations-wall__site', item.site || 'Selected sites'),
                node('strong', 'operations-wall__card-title', item.title), node('strong', 'operations-wall__value', item.value ?? '—'),
                node('span', 'operations-wall__card-detail', item.detail || 'Open for source detail'),
                node('span', 'operations-wall__card-open', 'View detail →'));
            return button;
        }

        function choose(id, manual = false) {
            if (!order().includes(id)) return;
            currentId = id;
            page = manual ? 0 : pageCursor.get(id) || 0;
            if (manual) pageCursor.set(id, 0);
            lastAdvance = Date.now();
            if (manual) { interactionPaused = true; touch(); }
            render();
        }

        function advance(direction = 1, manual = false) {
            const ids = order();
            if (!ids.length) return;
            // Rotate to the next screen every dwell period, then show the next
            // group of signals when this screen comes around again.
            if (!manual) pageCursor.set(currentId, wallNextPage(page, wallItems(current(), preferences.attention, preferences.hideZero).length));
            choose(ids[(ids.indexOf(currentId) + direction + ids.length) % ids.length], manual);
        }

        function showDetail(item) {
            if (!item) return;
            listOpen = false;
            find('detail-site').textContent = [item.category, item.site].filter(Boolean).join(' · ') || 'Operations detail';
            find('detail-title').textContent = item.title;
            const body = find('detail-body');
            body.replaceChildren(node('strong', `operations-wall__detail-value is-${tone(item.severity)}`, item.value ?? '—'),
                node('p', 'operations-wall__detail-text', item.detail));
            const facts = node('dl', 'operations-wall__facts');
            (item.facts || []).forEach((fact) => facts.append(node('dt', '', fact.label), node('dd', '', fact.value ?? '—')));
            body.append(facts);
            const href = safeReportUrl(item.href);
            if (href) {
                const link = node('a', 'btn-primary', 'Open full source report →');
                link.href = href;
                link.target = '_blank';
                link.rel = 'noopener';
                body.append(link, node('p', 'operations-wall__settings-note', 'Opens in a new tab, keeping this wall in place. The source report provides its own filters, export and further drill-down.'));
            } else body.append(node('p', 'operations-wall__settings-note', 'No further source report is available for this signal with your current access.'));
            if (item.sourceLabel) body.append(node('p', 'operations-wall__settings-note', item.sourceLabel));
            body.append(node('p', 'operations-wall__settings-note', `Detail from snapshot ${time(item.snapshotAt || snapshot?.generatedAt, true)} SAST. Close and reopen to see refreshed figures.`));
            if (!drawer.open) drawer.showModal();
            touch();
            tick();
        }

        function showAll() {
            listOpen = true;
            drawerItems = wallItems(current(), preferences.attention, preferences.hideZero).map((item) => ({
                ...item, snapshotAt: snapshot?.generatedAt, sourceLabel: item.sourceLabel || current()?.sourceLabel,
            }));
            find('detail-site').textContent = current()?.title || 'Operations';
            find('detail-title').textContent = 'Explore all signals';
            const input = node('input', 'operations-wall__search');
            input.type = 'search';
            input.placeholder = 'Find a site, item or signal…';
            input.setAttribute('aria-label', 'Search signals');
            const list = node('div', 'operations-wall__all');
            const fill = () => {
                const query = input.value.trim().toLocaleLowerCase();
                const matches = drawerItems.map((item, index) => ({ item, index })).filter(({ item }) => [item.title, item.site, item.detail, item.value].some((value) => string(value).toLocaleLowerCase().includes(query)));
                list.replaceChildren(...matches.map(({ item, index }) => {
                    const button = node('button', `operations-wall__all-item is-${tone(item.severity)}`);
                    button.type = 'button';
                    button.dataset.wallListItem = string(index);
                    button.append(node('span', '', item.site || 'Selected sites'), node('strong', '', item.title), node('span', '', item.value ?? '—'));
                    return button;
                }));
                if (!matches.length) list.append(node('p', '', 'No signals match this search.'));
            };
            input.addEventListener('input', fill);
            find('detail-body').replaceChildren(input, list);
            fill();
            drawer.showModal();
            touch();
            tick();
        }

        function fillSettings() {
            form.elements.seconds.value = string(preferences.seconds);
            form.elements.morning.checked = preferences.morning;
            form.elements.autoReturn.checked = preferences.autoReturn;
            form.querySelectorAll('[name="screens[]"]').forEach((checkbox) => { checkbox.checked = preferences.screens.includes(checkbox.value); });
            const select = form.querySelector('[name="branches[]"]');
            if (select.tomselect) {
                // A modal dialog makes the document behind it inert. Keep the
                // existing select component's dropdown within its own wrapper.
                const control = select.tomselect;
                control.settings.dropdownParent = null;
                control.wrapper.append(control.dropdown);
                control.dropdown.style.removeProperty('top');
                control.dropdown.style.removeProperty('left');
                control.dropdown.style.removeProperty('width');
                control.setValue(preferences.branches, true);
            }
            else Array.from(select.options).forEach((option) => { option.selected = preferences.branches.includes(option.value); });
        }

        async function refresh() {
            clearTimeout(refreshTimer);
            if (inFlight || suspended || preferences.scopeInvalid || accessChanged) return;
            inFlight = true;
            const version = scopeVersion;
            abort = new AbortController();
            const timeout = setTimeout(() => abort.abort(), 45000);
            const button = wall.querySelector('[data-wall-action="refresh"]');
            button.disabled = true;
            button.textContent = 'Refreshing…';
            try {
                const response = await fetch(wallSnapshotUrl(config.endpoint, preferences, window.location.href), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', cache: 'no-store', signal: abort.signal,
                });
                const policy = wallResponsePolicy(response.status, response.redirected);
                if (policy.accessChanged) {
                    // An authorization refusal is not an offline TV. Remove
                    // figures immediately instead of leaving revoked data up.
                    accessChanged = true;
                    snapshot = null;
                    drawerItems = [];
                    find('detail-body').replaceChildren();
                    find('detail-title').textContent = 'Detail';
                    find('detail-site').textContent = 'Operations detail';
                    if (drawer.open) drawer.close();
                    if (settings.open) settings.close();
                    pageCursor.clear();
                    wall.querySelector('[data-wall-action="settings"]').disabled = true;
                    throw new Error('Your session or access has changed. Previous figures have been cleared. Reload this page and sign in to continue.');
                }
                if (!response.ok) throw new Error(`The live feed could not refresh (${response.status}). It will retry in one minute.`);
                const data = await response.json();
                if (!Array.isArray(data.screens) || !data.generatedAt || !Number.isFinite(new Date(data.generatedAt).getTime())) throw new Error('The live feed returned an incomplete snapshot. It will retry in one minute.');
                const records = (value) => Array.isArray(value) && value.every((entry) => entry !== null && typeof entry === 'object');
                if (data.screens.some((screen) => !screen || !records(screen.metrics) || !records(screen.items)
                    || !Array.isArray(screen.gaps) || screen.items.some((item) => item.facts != null && !records(item.facts)))) {
                    throw new Error('The live feed returned incomplete source details. The last complete snapshot is kept until the next refresh.');
                }
                if (version !== scopeVersion || suspended) return;
                snapshot = data;
                error = '';
                if (initial) {
                    currentId = order()[0] || 'overview';
                    initial = false;
                    lastAdvance = Date.now();
                }
                render();
            } catch (failure) {
                if (version !== scopeVersion || suspended) return;
                error = failure.name === 'AbortError' ? 'The live feed took too long to respond. It will retry in one minute.' : failure.message;
                render();
            } finally {
                clearTimeout(timeout);
                inFlight = false;
                button.disabled = accessChanged;
                button.textContent = 'Refresh';
                if (suspended || accessChanged) return;
                if (refreshQueued) {
                    refreshQueued = false;
                    refresh();
                } else refreshTimer = setTimeout(refresh, REFRESH_MS);
            }
        }

        function tick() {
            const now = Date.now();
            find('clock').textContent = `${time(now)} SAST`;
            find('clock').dateTime = new Date(now).toISOString();
            if (preferences.autoReturn && !explicitPaused && now - lastInteraction >= RETURN_MS && !settings.open) {
                if (drawer.open) drawer.close();
                if (interactionPaused) { interactionPaused = false; lastAdvance = now; }
            }
            const stopped = paused();
            const pauseButton = wall.querySelector('[data-wall-action="pause"]');
            pauseButton.textContent = stopped ? 'Resume rotation' : 'Pause rotation';
            pauseButton.setAttribute('aria-pressed', stopped ? 'true' : 'false');
            const remaining = Math.max(0, preferences.seconds - Math.floor((now - lastAdvance) / 1000));
            const ids = order();
            const next = screens().find((screen) => screen.id === ids[(ids.indexOf(currentId) + 1) % ids.length]);
            find('rotation').textContent = stopped ? (drawer.open ? 'Exploring detail' : 'Rotation paused') : `Next screen in ${remaining}s`;
            find('next').textContent = stopped ? (explicitPaused || !preferences.autoReturn ? 'Press Resume to continue' : 'Returns after 2 minutes idle') : `Up next: ${next?.id === 'overview' ? 'Overview' : next?.title || 'Waiting for data'}`;
            find('progress').style.width = stopped ? '100%' : `${Math.max(0, Math.min(100, remaining / preferences.seconds * 100))}%`;
            find('progress').classList.toggle('is-paused', stopped);
            connection();
            if (!stopped && snapshot && remaining === 0) advance();
        }

        wall.addEventListener('click', async (event) => {
            touch();
            const tab = event.target.closest('[data-wall-screen]');
            if (tab) { choose(tab.dataset.wallScreen, true); return; }
            const item = event.target.closest('[data-wall-item]');
            if (item) { showDetail(wallItems(current(), preferences.attention, preferences.hideZero)[Number(item.dataset.wallItem)]); return; }
            const listItem = event.target.closest('[data-wall-list-item]');
            if (listItem && listOpen) { showDetail(drawerItems[Number(listItem.dataset.wallListItem)]); return; }
            const action = event.target.closest('[data-wall-action]')?.dataset.wallAction;
            if (action === 'pause') {
                if (paused()) { explicitPaused = false; interactionPaused = false; }
                else explicitPaused = true;
                lastAdvance = Date.now();
            } else if (action === 'refresh') refresh();
            else if (action === 'settings') { fillSettings(); settings.showModal(); }
            else if (action === 'settings-close') settings.close();
            else if (action === 'detail-close') drawer.close();
            else if (action === 'all') showAll();
            else if (action === 'page-prev' || action === 'page-next') {
                page = Math.max(0, page + (action === 'page-next' ? 1 : -1));
                interactionPaused = true;
                render();
            } else if (action === 'fullscreen') {
                try {
                    if (document.fullscreenElement) await document.exitFullscreen();
                    else await document.documentElement.requestFullscreen();
                } catch {
                    window.Agora?.notify?.warn('Full screen unavailable', 'Use the browser’s full-screen control instead.');
                }
            }
            tick();
        });
        wall.addEventListener('keydown', touch);
        wall.addEventListener('pointerdown', touch);
        wall.addEventListener('input', touch);
        drawer.addEventListener('scroll', touch, true);
        [drawer, settings].forEach((dialog) => dialog.addEventListener('close', () => { lastAdvance = Date.now(); touch(); tick(); }));
        [['attention', 'attention'], ['hide-zero', 'hideZero']].forEach(([control, preference]) => {
            find(control).addEventListener('change', (event) => {
                preferences[preference] = event.target.checked;
                page = 0;
                pageCursor.clear();
                save();
                render();
            });
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (accessChanged) return;
            const fields = new FormData(form);
            const previousScope = JSON.stringify(preferences.branches);
            const previousInvalid = preferences.scopeInvalid;
            preferences = wallPreferences({
                ...preferences, branches: fields.getAll('branches[]'),
                scopeInvalid: false,
                screens: fields.getAll('screens[]'), seconds: fields.get('seconds'),
                morning: fields.has('morning'), autoReturn: fields.has('autoReturn'),
            }, config.sites);
            save();
            updateScopeUrl();
            settings.close();
            if (previousInvalid || previousScope !== JSON.stringify(preferences.branches)) {
                scopeVersion++;
                snapshot = null;
                error = '';
                initial = true;
                page = 0;
                pageCursor.clear();
                if (inFlight) { refreshQueued = true; abort.abort(); }
                else refresh();
            }
            lastAdvance = Date.now();
            render();
        });
        document.addEventListener('keydown', (event) => {
            if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || settings.open || drawer.open) return;
            if (event.target.closest('input, select, textarea, [contenteditable="true"], [role="combobox"]')) return;
            if (event.code === 'Space') {
                if (event.target.closest('button, a')) return;
                event.preventDefault();
                wall.querySelector('[data-wall-action="pause"]').click();
            } else if (['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                event.preventDefault();
                advance(event.key === 'ArrowRight' ? 1 : -1, true);
            }
        });
        document.addEventListener('fullscreenchange', () => {
            const full = !!document.fullscreenElement;
            document.body.classList.toggle('operations-wall-fullscreen', full);
            wall.querySelector('[data-wall-action="fullscreen"]').textContent = full ? 'Exit full screen' : 'Full screen';
        });
        document.addEventListener('visibilitychange', () => {
            lastAdvance = Date.now();
            if (!document.hidden && snapshot && Date.now() - new Date(snapshot.generatedAt).getTime() >= REFRESH_MS) refresh();
        });
        window.addEventListener('offline', () => {
            error = 'This TV is offline. The last snapshot is kept visible; reconnect to receive new figures.';
            connection();
        });
        window.addEventListener('online', refresh);
        window.addEventListener('pagehide', () => {
            suspended = true;
            clearTimeout(refreshTimer);
            clearInterval(clockTimer);
            abort?.abort();
        });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            suspended = false;
            lastAdvance = Date.now();
            clockTimer = setInterval(tick, 1000);
            refreshQueued = inFlight;
            refresh();
        });
        clockTimer = setInterval(tick, 1000);
        updateScopeUrl();
        render();
        readiness.push(refresh());
    });
    // Only the first snapshot (or its rendered error) belongs to page setup.
    // Polling, rotation and later refreshes remain independent actions.
    return Promise.all(readiness);
}
