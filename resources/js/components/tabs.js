/**
 * Tabs, in the mode where they are panes rather than links.
 *
 * The link mode of <x-tabs> gets no JavaScript at all — it is anchors, the
 * server decides what is on screen, and a tab is a place you can send someone.
 * This module exists only for the panel mode, and only for the one thing the
 * platform does not give away:
 *
 *   - **The panes are already correct before this runs.** <x-tab-panel> ships
 *     the inactive ones with `hidden` from the server, so there is no flash of
 *     every pane at once and the page is usable with scripting off.
 *   - **What is added is memory.** Someone who lives on the "Imported" tab of
 *     the import screen should find it on "Imported" tomorrow. localStorage,
 *     per tab set, keyed by whatever `persist` the view passed.
 *   - **And the keyboard.** A tablist is expected to move on the arrow keys,
 *     Home and End, with one tab stop for the whole set. The browser does not
 *     supply that for buttons; a set of eight tabs that costs eight tab presses
 *     to walk past is the thing this avoids.
 *
 *   - **Lazy panes** (`<x-tab-panel src>`). A pane with a source is fetched
 *     the first time its tab is opened and not before — the server renders
 *     the HTML, and window.Agora.hydrate() wires what arrives exactly as the
 *     page's own markup was wired at load. Built for the recon centre, whose
 *     tabs are three procedures over one site-month. A wait long enough to
 *     notice shows the loader.
 *   - **Stale panes.** Something inside a pane that writes and stays on the
 *     page (a bulk match) dispatches `tabs:changed`; every OTHER lazy pane in
 *     the set that has already loaded is marked stale and fetched again the
 *     next time it is opened. A pane is never refetched behind the reader's
 *     back while they are looking at it.
 *   - **The tab in the URL** (`data-tabs-query`), with replaceState, so a
 *     reload or a copied link opens the same tab. The server reads the same
 *     parameter.
 *
 * Storage is wrapped in try/catch throughout: private mode and a browser set
 * to block site data both throw on access, and a tab strip must not take the
 * page down with it because a preference could not be saved.
 */
export default function tabs(root = document) {
    root.querySelectorAll('[data-tabs]:not([data-tabs-ready])').forEach((set) => {
        set.setAttribute('data-tabs-ready', '');

        // Direct children only: a lazy pane can bring a tab set of its own,
        // and its buttons are not this strip's.
        const strip = set.querySelector(':scope > .tabs');
        const buttons = strip ? Array.from(strip.querySelectorAll('[data-tab]')) : [];
        const panels = Array.from(set.querySelectorAll(':scope > [data-tab-panel]'));
        if (! strip || buttons.length === 0) return;

        const store = set.dataset.tabsPersist ? `agora.tabs.${set.dataset.tabsPersist}` : null;
        const query = set.dataset.tabsQuery || null;

        // The panels have no ids of their own — a view should not have to
        // invent one — so they are linked to their tabs here.
        buttons.forEach((button) => {
            const panel = panels.find((p) => p.dataset.tabPanel === button.dataset.tab);
            if (! panel) return;
            panel.id = panel.id || `${button.id}-panel`;
            button.setAttribute('aria-controls', panel.id);
            panel.setAttribute('aria-labelledby', button.id);
        });

        function show(want, remember) {
            if (! buttons.some((b) => b.dataset.tab === want)) return false;

            buttons.forEach((button) => {
                const on = button.dataset.tab === want;
                button.classList.toggle('on', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
                button.tabIndex = on ? 0 : -1;
            });

            panels.forEach((panel) => {
                panel.hidden = panel.dataset.tabPanel !== want;
            });

            const panel = panels.find((p) => p.dataset.tabPanel === want);
            if (panel && panel.dataset.tabSrc) load(panel, button(want));

            if (remember && store) {
                try { localStorage.setItem(store, want); } catch { /* storage blocked */ }
            }

            if (remember && query) {
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set(query, want);
                    window.history.replaceState(window.history.state, '', url);
                } catch { /* an address that cannot be rewritten is not worth failing over */ }
            }

            return true;
        }

        function button(key) {
            return buttons.find((b) => b.dataset.tab === key)?.textContent.trim() || '';
        }

        // A write inside one pane dirties the others that have loaded. The
        // pane it came from is current by definition.
        set.addEventListener('tabs:changed', (event) => {
            const from = event.target.closest('[data-tab-panel]');
            panels
                .filter((p) => p !== from && p.dataset.tabSrc && p.dataset.tabLoaded === 'yes')
                .forEach((p) => { p.dataset.tabStale = 'yes'; });
        });

        // "Try again" on a pane that could not be read.
        set.addEventListener('click', (event) => {
            const retry = event.target.closest('[data-tab-retry]');
            if (! retry) return;
            const panel = retry.closest('[data-tab-panel]');
            if (panel) load(panel, button(panel.dataset.tabPanel), true);
        });

        let remembered = null;
        if (store) {
            try { remembered = localStorage.getItem(store); } catch { /* storage blocked */ }
        }

        // Falling back to the button the server marked also repairs the one
        // mistake a view can make here — panel mode with no `active`, which
        // renders every pane — rather than leaving a page of stacked panes.
        if (! remembered || ! show(remembered, false)) {
            const current = buttons.find((b) => b.classList.contains('on')) || buttons[0];
            show(current.dataset.tab, false);
        }

        strip.addEventListener('click', (event) => {
            const button = event.target.closest('[data-tab]');
            if (button) show(button.dataset.tab, true);
        });

        strip.addEventListener('keydown', (event) => {
            const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
            if (! keys.includes(event.key)) return;

            const at = buttons.indexOf(document.activeElement);
            if (at < 0) return;

            event.preventDefault();

            const next = event.key === 'Home' ? 0
                : event.key === 'End' ? buttons.length - 1
                    : (at + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;

            buttons[next].focus();
            show(buttons[next].dataset.tab, true);
        });
    });
}

/**
 * Fetch a lazy pane's fragment, once — again only if it has gone stale.
 *
 * The server renders HTML and this inserts it; window.Agora.hydrate() then
 * does for the new markup what app.js did for the page at load. A response
 * that was redirected is the session ending (the login page, answering 200),
 * which must not be pasted into the pane as if it were the answer.
 */
async function load(panel, label, force = false) {
    const loaded = panel.dataset.tabLoaded === 'yes' && panel.dataset.tabStale !== 'yes';
    if ((loaded && ! force) || panel.dataset.tabLoading === 'yes') return;

    panel.dataset.tabLoading = 'yes';
    panel.setAttribute('aria-busy', 'true');

    // The loader decides for itself whether the wait is long enough to show.
    const done = window.Agora?.loader?.within
        ? window.Agora.loader.within(panel, label ? `Reading ${label.toLowerCase()}…` : 'Reading…')
        : () => {};

    try {
        const response = await fetch(panel.dataset.tabSrc, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        });

        if (response.redirected) throw new Error('your session has ended — reload the page to sign in again');
        if (! response.ok) throw new Error(`the server answered ${response.status}`);

        const html = await response.text();

        done();
        panel.innerHTML = html;
        panel.dataset.tabLoaded = 'yes';
        delete panel.dataset.tabStale;
        window.Agora?.hydrate?.(panel);
    } catch (error) {
        done();
        panel.innerHTML = '<p class="tabpane-error">This tab could not be read — '
            + escapeHtml(error.message || String(error))
            + '. <button type="button" class="btn-ghost sm" data-tab-retry>Try again</button></p>';
    } finally {
        delete panel.dataset.tabLoading;
        panel.removeAttribute('aria-busy');
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}
