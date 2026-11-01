/**
 * The coffee cup — the one way a wait is drawn (<x-loader>).
 *
 * ZP asked through Ryan on 23 Sep 2026 for the busy states replaced with a cup
 * that fills, carries the ZRP badge, steams when full and goes round again
 * until the action completes. This is the one place that decides WHEN it is
 * seen; the component decides what it looks like.
 *
 * ACTION WAITS. Nothing appears for a wait under DELAY. A cup that
 * flashes for 80 ms on every quick press is noise, and people learn to stop
 * looking at it — which is the opposite of what a busy state is for. Every
 * action entry point below goes through the same timer. Initial page setup
 * is separate: its loader is already visible in the first server paint.
 *
 * Five ways in, and they are the whole API:
 *
 *   const wait = window.Agora.loader.show('Balancing FNB…');   the page-level
 *   wait.update('Matching 3 of 43…'); wait.hide();             overlay
 *
 *   const done = window.Agora.loader.within(pane, 'Reading…');  a cup in the
 *   done();                                                     place the answer
 *                                                               will land
 *
 *   <form data-loader="Reconciling…">   a navigation: shown on submit, and
 *                                       cleared by the page that replaces it
 *
 *   const stop = window.Agora.loader.beside(statusLine);        a small cup in
 *   stop();                                                     front of a line
 *                                                               that already
 *                                                               says what is
 *                                                               happening
 *
 *   window.Agora.loader.submitting(form)   for code that submits a form
 *                                          itself (confirm-form.js)
 *
 * A navigation leaves the overlay up on purpose — the next page replaces it.
 * The one way it could outlive its page is the back button restoring this one
 * from the bfcache, so `pageshow` with `persisted` takes it down.
 */
const DELAY = 300;
const STARTUP_LIMIT = 60000;

export default function loader() {
    const overlay = document.querySelector('[data-loader-overlay]');
    const label = overlay?.querySelector('[data-loader-label]');
    const defaultArt = overlay?.querySelector('.loader-art')?.cloneNode(true);
    const startup = window.Agora?.startup;

    let timer = null;
    let holders = 0;
    let startupDone = false;
    let startupTimer = null;
    let resolveReady;
    const readiness = new Promise((resolve) => { resolveReady = resolve; });
    const startupHeld = () => overlay?.hasAttribute('data-loader-startup')
        && document.documentElement.hasAttribute('data-app-starting');
    const pageHeld = () => startupHeld() || overlay?.hasAttribute('data-loader-hold');

    function finishStartup(reason, outcome = null) {
        if (startupDone) return;
        startupDone = true;
        if (startupTimer !== null) window.clearTimeout(startupTimer);
        overlay?.removeAttribute('data-loader-startup');
        overlay?.removeAttribute('data-loader-hold');
        startup?.finish();
        document.documentElement.removeAttribute('data-app-starting');
        if (overlay && holders === 0) overlay.hidden = true;
        const detail = { reason, outcome };
        document.documentElement.dataset.appReady = reason;
        document.dispatchEvent(new CustomEvent('agora:ready', { detail }));
        resolveReady(detail);
    }

    // The head guard also covers a missing/broken main bundle. This timer
    // bounds an initializer that never settles after the bundle has started.
    if (startupHeld()) startupTimer = window.setTimeout(() => finishStartup('timeout'), STARTUP_LIMIT);

    function ready(initialization) {
        Promise.resolve(initialization)
            .then(async (outcome) => {
                await afterPaint();
                finishStartup(outcome?.failures?.length ? 'partial' : 'ready', outcome);
            })
            .catch((error) => {
                console.error('Page setup could not finish.', error);
                finishStartup('partial');
            });

        return readiness;
    }

    function show(text = 'Working…') {
        if (!overlay) return { update() {}, hide() {} };

        holders += 1;
        if (label) label.textContent = text;

        if (overlay.hidden && timer === null) {
            timer = window.setTimeout(() => {
                timer = null;
                if (holders > 0) overlay.hidden = false;
            }, DELAY);
        }

        let released = false;

        return {
            update(next) { if (label && !released) label.textContent = next; },
            hide() {
                if (released) return;
                released = true;
                holders = Math.max(0, holders - 1);
                if (holders === 0) clear();
            },
        };
    }

    function clear() {
        holders = 0;
        if (timer !== null) { window.clearTimeout(timer); timer = null; }
        if (overlay && !pageHeld()) overlay.hidden = true;
    }

    /**
     * A cup inside `el`, in place of what it holds, until done() is called —
     * the recon centre's tabs, while their fragment is on its way. The cup is
     * a clone of the overlay's, with its clip-path ids renamed so it never
     * depends on an element that is hidden.
     */
    function within(el, text = 'Reading…') {
        if (!overlay || !el) return () => {};

        let node = null;
        const hiddenKids = [];

        const pending = window.setTimeout(() => {
            node = document.createElement('div');
            node.className = 'loader loader-inline';
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            node.append(uniquely(overlay.querySelector('.loader-box').cloneNode(true)));
            const words = node.querySelector('[data-loader-label]');
            if (words) words.textContent = text;

            Array.from(el.children).forEach((child) => {
                if (!child.hidden) { child.hidden = true; hiddenKids.push(child); }
            });
            el.append(node);
        }, DELAY);

        return () => {
            window.clearTimeout(pending);
            if (node) node.remove();
            hiddenKids.forEach((child) => { child.hidden = false; });
        };
    }

    /**
     * A small cup in front of `el`, which keeps its own words — the "Every
     * site" runner, whose status line already says which site it is on and
     * whose table fills in as it goes. An overlay there would hide the very
     * progress it is waiting on.
     */
    function beside(el) {
        if (!overlay || !el) return () => {};

        let node = null;

        const pending = window.setTimeout(() => {
            node = document.createElement('span');
            node.className = 'loader-mini';
            node.setAttribute('aria-hidden', 'true');
            // `.loader-art`: the cup, or on the stock recon screens the cart.
            node.append(uniquely(overlay.querySelector('.loader-art').cloneNode(true)));
            el.before(node);
        }, DELAY);

        return () => {
            window.clearTimeout(pending);
            if (node) node.remove();
        };
    }

    function submitting(form) {
        if (form?.dataset && 'loader' in form.dataset) show(form.dataset.loader || 'Working…');
    }

    // A form that asks for it, submitted by a person. confirm-form.js cancels
    // the first submit to ask its question, so a prevented submit is not a
    // navigation — it calls submitting() itself once the answer is yes.
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const form = event.target;
        if (form instanceof HTMLFormElement && form.matches('[data-loader]') && !form.target) submitting(form);
    });

    // Module menu links use the same loader as their destination. Keep normal
    // browser navigation: modified clicks, downloads and other windows need no
    // overlay on this page. The art is shared with the static menu icon.
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[data-loader]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || (url.pathname === window.location.pathname && url.search === window.location.search)) return;

        const art = link.querySelector('[data-module-art]');
        if (art && overlay) {
            const clone = uniquely(art.cloneNode(true));
            clone.dataset.moduleArtAnimated = 'true';
            overlay.querySelector('.loader-art')?.replaceWith(clone);
        }
        show(link.dataset.loader || 'Opening…');
    });

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        clear();
        finishStartup('bfcache');
        if (defaultArt) overlay?.querySelector('.loader-art')?.replaceWith(defaultArt.cloneNode(true));
    });

    /*
     * A PAGE THAT HOLDS THE LOADER (Ryan, 7 Oct 2026: "show the loader until
     * the grid has been initialized"). The shell rendered the overlay already
     * showing; it comes down once every `table[data-loader-hold]` is marked
     * ready by table-tools — which has usually happened by the time this runs,
     * as table-tools is wired first. A table that never reports (no tools on
     * it) does not hold anything: only tables that carry tools are waited on,
     * and the stylesheet gives up on its own after a minute whatever happens.
     */
    if (overlay?.hasAttribute('data-loader-hold')) {
        const waiting = () => Array.from(document.querySelectorAll('table[data-loader-hold][data-table-tools]'))
            .filter((table) => !table.hasAttribute('data-tt-ready'));

        const release = () => {
            if (!overlay.hasAttribute('data-loader-hold') || waiting().length > 0) return;
            overlay.removeAttribute('data-loader-hold');
            if (holders === 0 && !startupHeld()) overlay.hidden = true;
        };

        document.addEventListener('table-tools:ready', release);
        release();
    }

    return { show, hide: clear, within, beside, submitting, ready };
}

/** Let completed layout/resize work cross a paint before showing the page. */
export function afterPaint() {
    return new Promise((resolve) => {
        if (document.hidden || typeof window.requestAnimationFrame !== 'function') {
            resolve();
            return;
        }
        window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
    });
}

/** Rename every id in a cloned cup, and every url(#id) that points at one. */
function uniquely(box) {
    const suffix = Math.random().toString(36).slice(2, 8);

    box.querySelectorAll('[id]').forEach((el) => {
        const old = el.id;
        const next = `${old}-${suffix}`;
        el.id = next;
        box.querySelectorAll(`[clip-path="url(#${old})"]`).forEach((user) => {
            user.setAttribute('clip-path', `url(#${next})`);
        });
    });

    return box;
}
