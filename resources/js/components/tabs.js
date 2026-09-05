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
 * Storage is wrapped in try/catch throughout: private mode and a browser set
 * to block site data both throw on access, and a tab strip must not take the
 * page down with it because a preference could not be saved.
 */
export default function tabs(root = document) {
    root.querySelectorAll('[data-tabs]:not([data-tabs-ready])').forEach((set) => {
        set.setAttribute('data-tabs-ready', '');

        const strip = set.querySelector('.tabs');
        const buttons = Array.from(set.querySelectorAll('.tabs [data-tab]'));
        const panels = Array.from(set.querySelectorAll('[data-tab-panel]'));
        if (! strip || buttons.length === 0) return;

        const store = set.dataset.tabsPersist ? `agora.tabs.${set.dataset.tabsPersist}` : null;

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

            if (remember && store) {
                try { localStorage.setItem(store, want); } catch { /* storage blocked */ }
            }

            return true;
        }

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
