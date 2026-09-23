/**
 * The coffee cup — the one way a wait is drawn (<x-loader>).
 *
 * ZP asked through Ryan on 23 Sep 2026 for the busy states replaced with a cup
 * that fills, carries the ZRP badge, steams when full and goes round again
 * until the action completes. This is the one place that decides WHEN it is
 * seen; the component decides what it looks like.
 *
 * ONE RULE ABOUT TIME. Nothing appears for a wait under DELAY. A cup that
 * flashes for 80 ms on every quick press is noise, and people learn to stop
 * looking at it — which is the opposite of what a busy state is for. Every
 * entry point below goes through the same timer.
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

export default function loader() {
    const overlay = document.querySelector('[data-loader-overlay]');
    const label = overlay?.querySelector('[data-loader-label]');

    let timer = null;
    let holders = 0;

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
        if (overlay) overlay.hidden = true;
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
            node.append(uniquely(overlay.querySelector('.loader-cup').cloneNode(true)));
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

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) clear();
    });

    return { show, hide: clear, within, beside, submitting };
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
