/**
 * Remembering which <details> a person left open.
 *
 * Everything foldable in Agora is a native <details> — <x-card collapsible>,
 * <x-notice collapsible>, <x-params collapsible>, <x-exception-row>,
 * <x-sqlbox>. That is deliberate: the browser supplies the keyboard
 * behaviour, the disclosure semantics and a correct first paint with no
 * script.
 *
 * The one thing it does not supply is memory. A report someone runs every
 * morning should not fold its parameters away again on every load, and a
 * reviewer who opened the SQL behind a figure should find it open when they
 * come back to check it. So: mark the element `data-remember="key"` and the
 * state is kept for this person on this browser.
 *
 * Not the server, and not a cookie. This is a per-browser convenience with no
 * business meaning — it does not belong in agora.UserPreference next to the
 * theme, and it must not be sent on every request.
 */
export default function disclosure(root = document) {
    root.querySelectorAll('details[data-remember]:not([data-remember-ready])').forEach((el) => {
        el.setAttribute('data-remember-ready', '');

        const key = `agora.open.${el.dataset.remember}`;

        let stored = null;
        try { stored = localStorage.getItem(key); } catch { /* storage blocked */ }

        // Only an explicit stored answer overrides the server's choice. An
        // absent key means "never touched", not "closed" — otherwise the first
        // visit would close everything the view deliberately opened.
        if (stored === '1') el.open = true;
        if (stored === '0') el.open = false;

        el.addEventListener('toggle', () => {
            try { localStorage.setItem(key, el.open ? '1' : '0'); } catch { /* storage blocked */ }
        });
    });
}
