/**
 * The behaviour of <x-data-grid>, and of <x-drawer>.
 *
 * What is NOT here matters as much as what is:
 *
 *   Sorting and paging are links. The server sorts and pages (the procedure
 *   does, for a procedure-backed grid), so a header is an <a> and the browser
 *   does the rest. Nothing here re-sorts rows in the DOM — that would be a
 *   second ordering, over one page, disagreeing with the one the extract uses.
 *
 *   Filtering is a form. The header filter inputs belong to the scope form
 *   through `form=`, so the grid narrows with no JavaScript at all.
 *
 *   Row expansion is row-detail.js. It already fetches once, keeps the result,
 *   leaves clicks on inner controls alone and takes HTML back rather than JSON.
 *   The grid emits `data-row-detail` and stays out of the way.
 *
 *   Bulk selection is check-all.js, for the same reason: the header box, the
 *   indeterminate state and the click-does-not-expand-the-row rule are already
 *   solved there. This file only counts what is ticked.
 *
 * So what IS here is the part that is genuinely the grid's: the column chooser,
 * the resize, the text size, and persisting all three to agora.UserGridColumn
 * on a debounce; plus the drawer that the extract opens in.
 *
 * Everything degrades. A grid whose script never loads still sorts, pages,
 * filters, searches, expands its rows and downloads its extract — it just does
 * not remember the columns.
 */

/** How long to wait after the last change before saving. */
const SAVE_AFTER_MS = 500;

/** A resize below or above this is a fiddle, not a preference. Mirrors config/grids.php. */
const WIDTH_MIN = 60;
const WIDTH_MAX = 640;

export default function dataGrid() {
    document.querySelectorAll('[data-grid]').forEach((root) => new Grid(root));
    document.querySelectorAll('[data-drawer]').forEach((panel) => drawer(panel));
    document.querySelectorAll('[data-drawer-open]').forEach((trigger) => {
        trigger.addEventListener('click', () => open(document.getElementById(trigger.dataset.drawerOpen), trigger));
    });
}

class Grid {
    constructor(root) {
        this.root = root;
        this.key = root.dataset.gridKey;
        this.url = root.dataset.stateUrl;
        this.table = root.querySelector('table.dg');
        this.timer = null;

        this.chooser();
        this.resizing();
        this.selection();
        this.busy();
    }

    /* --------------------------------------------------------------- busy */

    /**
     * The loading state (feature-rules, proposed §C).
     *
     * These procedures run over big tables and a grid that goes blank for four
     * seconds reads as broken. Sorting, paging and filtering are navigations,
     * so what is actually happening is a page load — this says so OVER the
     * rows, which stay on screen and stay readable, rather than replacing them
     * with a spinner and taking away the numbers the person was looking at.
     *
     * It is armed on the way out and never disarmed: the page is leaving. The
     * one case that has to be excluded is a link that is not a navigation —
     * the extract, which downloads without replacing the page and would
     * otherwise leave the grid saying "Running…" for ever.
     */
    busy() {
        const note = this.root.querySelector('[data-busy]');
        if (!note) return;

        const start = () => {
            note.hidden = false;
            this.root.classList.add('is-busy');
        };

        this.root.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.hasAttribute('data-extract') || link.closest('[data-drawer]')) return;
            start();
        });

        const form = this.root.querySelector('form.dg-scope');
        if (form) form.addEventListener('submit', start);

        // Coming back through the history cache, the page is the old one and
        // the note would still be showing from when it left.
        window.addEventListener('pageshow', () => {
            note.hidden = true;
            this.root.classList.remove('is-busy');
        });
    }

    /* ------------------------------------------------------------ chooser */

    chooser() {
        const list = this.root.querySelector('[data-chooser-list]');
        if (!list) return;

        list.querySelectorAll('[data-chooser-visible]').forEach((box) => {
            box.addEventListener('change', () => {
                this.showColumn(box.value, box.checked);
                this.save();
            });
        });

        list.querySelectorAll('[data-chooser-up], [data-chooser-down]').forEach((button) => {
            button.addEventListener('click', () => {
                const item = button.closest('[data-chooser-item]');
                const sibling = button.hasAttribute('data-chooser-up')
                    ? item.previousElementSibling
                    : item.nextElementSibling;

                if (!sibling) return;

                // Moving the row in the panel is the whole gesture; the table
                // follows it. Doing it the other way round means the panel and
                // the grid can disagree about the order after a failed save.
                if (button.hasAttribute('data-chooser-up')) sibling.before(item);
                else sibling.after(item);

                this.reorder();
                this.save();
                button.focus();
            });
        });

        const reset = this.root.querySelector('[data-chooser-reset]');
        if (reset) {
            reset.addEventListener('click', async () => {
                const notify = window.Agora && window.Agora.notify;
                const go = notify
                    ? await notify.confirm('Put the columns back?', {
                        text: 'Your order, widths, hidden columns and text size for this grid are forgotten. '
                            + 'The rows themselves are untouched.',
                        action: 'Reset',
                    })
                    : true;

                if (!go) return;

                await fetch(this.url, {
                    method: 'DELETE',
                    headers: this.headers(),
                    credentials: 'same-origin',
                });

                window.location.reload();
            });
        }

        const size = this.root.querySelector('[data-text-size]');
        if (size) {
            size.addEventListener('change', () => {
                this.root.classList.remove('dg-text-compact', 'dg-text-normal', 'dg-text-large');
                this.root.classList.add(`dg-text-${size.value}`);
                this.save();
            });
        }

        // The page size is stored like the rest of the layout, but it also
        // changes what the server has to fetch — so it reloads rather than
        // waiting for the next click to pick it up.
        const rows = this.root.querySelector('[data-page-size]');
        if (rows) {
            rows.addEventListener('change', async () => {
                await this.save(true);
                window.location.reload();
            });
        }
    }

    /** Hide or show one column, header, cells and card field alike. */
    showColumn(key, visible) {
        if (!this.table) return;

        this.table
            .querySelectorAll(`[data-column="${cssEscape(key)}"]`)
            .forEach((cell) => { cell.hidden = !visible; });
    }

    /** Put the table's columns in the order the panel now shows. */
    reorder() {
        if (!this.table) return;

        const order = this.order();

        this.table.querySelectorAll('tr').forEach((row) => {
            const cells = new Map();
            row.querySelectorAll('[data-column]').forEach((cell) => cells.set(cell.dataset.column, cell));

            // Append in order. Appending an element that is already in the row
            // moves it, so this is a reorder rather than a rebuild — the
            // checkboxes and any focus inside a cell survive it.
            order.forEach((key) => {
                const cell = cells.get(key);
                if (cell) row.append(cell);
            });
        });
    }

    /* ------------------------------------------------------------- resize */

    resizing() {
        if (!this.table) return;

        this.table.querySelectorAll('[data-resize]').forEach((handle) => {
            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const th = handle.closest('th');
                const from = event.clientX;
                const start = th.getBoundingClientRect().width;

                // Fixed layout is what makes a pixel width mean anything: under
                // auto layout the browser re-divides the space and a saved
                // width is only a suggestion.
                this.table.style.tableLayout = 'fixed';
                handle.setPointerCapture(event.pointerId);
                this.root.classList.add('is-resizing');

                const move = (e) => {
                    const width = clamp(Math.round(start + (e.clientX - from)), WIDTH_MIN, WIDTH_MAX);
                    th.style.width = `${width}px`;
                };

                const stop = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', stop);
                    handle.removeEventListener('pointercancel', stop);
                    this.root.classList.remove('is-resizing');
                    // On the way up, not on every frame — a drag is a hundred
                    // pointermove events and each one is not a save.
                    this.save();
                };

                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', stop);
                handle.addEventListener('pointercancel', stop);
            });
        });
    }

    /* ---------------------------------------------------------- selection */

    selection() {
        const bar = this.root.querySelector('[data-selection]');
        if (!bar || !this.table) return;

        const count = bar.querySelector('[data-selection-count]');
        const boxes = () => Array.from(this.table.querySelectorAll('[data-check]'));

        const sync = () => {
            const ticked = boxes().filter((b) => b.checked).length;
            bar.hidden = ticked === 0;
            if (count) count.textContent = String(ticked);
        };

        // check-all.js owns the header box and the indeterminate state; this
        // listens rather than binding its own, so there is one implementation
        // of "everything on this page" and this only ever counts.
        this.table.addEventListener('change', (event) => {
            if (event.target.matches('[data-check], [data-check-all]')) sync();
        });

        const clear = bar.querySelector('[data-selection-clear]');
        if (clear) {
            clear.addEventListener('click', () => {
                boxes().forEach((b) => { b.checked = false; });
                const master = this.table.querySelector('[data-check-all]');
                if (master) { master.checked = false; master.indeterminate = false; }
                sync();
            });
        }

        sync();
    }

    /* ------------------------------------------------------------ persist */

    order() {
        return Array.from(this.root.querySelectorAll('[data-chooser-item]'))
            .map((item) => item.dataset.chooserItem);
    }

    hidden() {
        return Array.from(this.root.querySelectorAll('[data-chooser-visible]'))
            .filter((box) => !box.checked)
            .map((box) => box.value);
    }

    /**
     * The widths that have actually been set.
     *
     * Only columns carrying an inline width go in. A column brought back after
     * a resize therefore has no width of its own and inherits auto sizing
     * instead of rendering at zero — ZP's bug, and the reason feature-rules
     * §3.6 names it.
     */
    widths() {
        const widths = {};
        if (!this.table) return widths;

        this.table.querySelectorAll('th[data-column]').forEach((th) => {
            const width = parseInt(th.style.width, 10);
            if (Number.isFinite(width)) widths[th.dataset.column] = clamp(width, WIDTH_MIN, WIDTH_MAX);
        });

        return widths;
    }

    state() {
        const size = this.root.querySelector('[data-text-size]');
        const rows = this.root.querySelector('[data-page-size]');
        const widths = this.widths();

        const state = {
            column_order: this.order(),
            hidden: this.hidden(),
            text_size: size ? size.value : 'normal',
        };

        // Absent, not `{}` — an empty widths map is a state that keeps being
        // applied, and "reset the widths" then never reaches auto layout.
        if (Object.keys(widths).length) state.widths = widths;
        if (rows) state.page_size = Number(rows.value);

        return state;
    }

    /** Debounced, unless the caller is about to reload and needs it landed. */
    save(immediate = false) {
        window.clearTimeout(this.timer);

        if (immediate) return this.post();

        return new Promise((resolve) => {
            this.timer = window.setTimeout(() => resolve(this.post()), SAVE_AFTER_MS);
        });
    }

    async post() {
        try {
            const response = await fetch(this.url, {
                method: 'POST',
                headers: this.headers(),
                credentials: 'same-origin',
                body: JSON.stringify(this.state()),
            });

            if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
        } catch (error) {
            // Said out loud, once. A layout that silently stops persisting is
            // a user who rearranges the same grid every morning and assumes
            // that is how it works.
            if (window.Agora && window.Agora.notify) {
                window.Agora.notify.toast('Your column layout could not be saved', 'error');
            }
        }
    }

    headers() {
        const token = document.querySelector('meta[name="csrf-token"]');

        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token ? token.content : '',
        };
    }
}

/* ================================================================= drawer */

function drawer(panel) {
    panel.querySelectorAll('[data-drawer-close]').forEach((close) => {
        close.addEventListener('click', () => shut(panel));
    });

    const copy = panel.querySelector('[data-drawer-copy]');
    if (copy) {
        copy.addEventListener('click', async () => {
            const area = panel.querySelector('textarea');
            if (!area) return;

            area.select();

            try {
                await navigator.clipboard.writeText(area.value);
                if (window.Agora && window.Agora.notify) window.Agora.notify.toast('Copied');
            } catch (error) {
                // A locked-down browser blocks the clipboard API. The text is
                // already selected, so Ctrl-C still works and saying so is more
                // use than an error nobody can act on.
                if (window.Agora && window.Agora.notify) {
                    window.Agora.notify.toast('Selected — press Ctrl-C to copy', 'info');
                }
            }
        });
    }
}

function open(panel, trigger) {
    if (!panel) return;

    panel.hidden = false;
    panel.dataset.returnTo = trigger && trigger.id ? trigger.id : '';
    panel.opener = trigger || null;

    const focusable = panel.querySelector('button, [href], textarea, input, select');
    if (focusable) focusable.focus();

    // One listener per opening, removed on close. A drawer that leaves an
    // Escape handler behind starts closing the next one that opens.
    panel.escape = (event) => { if (event.key === 'Escape') shut(panel); };
    document.addEventListener('keydown', panel.escape);
}

function shut(panel) {
    panel.hidden = true;

    if (panel.escape) {
        document.removeEventListener('keydown', panel.escape);
        panel.escape = null;
    }

    // Focus goes back where it came from. Without this it lands on <body> and
    // the next Tab starts at the top of the page.
    if (panel.opener) panel.opener.focus();
}

/* ================================================================= helpers */

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

/** CSS.escape is not in every browser Agora has to run in. */
function cssEscape(value) {
    return window.CSS && window.CSS.escape ? window.CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
}
