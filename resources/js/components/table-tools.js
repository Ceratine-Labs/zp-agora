/**
 * Sorting and Excel-style column filters on an ordinary <x-table>.
 *
 * WHY THIS EXISTS BESIDE <x-data-grid>. The grid sorts and filters in the
 * SOURCE: a click is a navigation, the procedure runs again, and the answer is
 * the whole result set rather than the page you are looking at. That is the
 * right shape for a list of ninety thousand rows and the wrong shape for the
 * screens this is written for. A recon run's proposals, a group's twenty-six
 * sites, the two sides of a manual match — every one of those arrives complete,
 * in one response, and carries tick boxes that decide what a commit stamps.
 * They cannot become grids (the tick boxes and the expanding rows are the
 * screen), and they should not have to re-run a procedure to answer "show me
 * the bank-only ones", which is a question about rows already on the page.
 *
 * So: opt in with `<x-table tools>` and the head gains a sort control per
 * column and a filter row under it. Nothing is fetched, nothing is navigated,
 * and the answer appears as fast as the browser can hide a row.
 *
 * WHAT A FILTER DOES TO A COMMIT — the part that is about money rather than
 * about tables. These screens stamp the customer's live database from ticked
 * rows. If filtering were purely cosmetic, a clerk could narrow to one site,
 * press Reconcile, and stamp four hundred rows they could not see. So a row a
 * filter hides has its inputs DISABLED, which takes it out of the submission,
 * and the action bar's count is recomputed from what is left. What you see is
 * what you stamp. Clear the filter and the ticks come back exactly as they
 * were — disabling is reversible, unticking would not have been.
 *
 * Sorting moves the row and everything row-detail.js has opened beneath it, or
 * a drilled panel would end up explaining somebody else's total.
 *
 * The markup contract:
 *
 *   <table class="dt" data-table-tools>          — the whole thing
 *   <th data-no-tools>                            — no sort, no filter
 *   <td data-sort="2026-09-08">8 Sep</td>         — sort and filter on this
 *                                                   instead of the rendered text
 */

const NOTHING = '—';
const MAX_SET = 400;

export default function tableTools() {
    stickyOffset();

    // Every .dt, not only the ones with tools: the data grid's own filter row
    // is a second head row too, and it has been sliding under the first since
    // the day the scroll container got a height.
    document.querySelectorAll('table.dt').forEach(stickyHead);

    document.querySelectorAll('table[data-table-tools]').forEach((table) => {
        const tools = new TableTools(table);

        if (!tools.usable) return;

        tools.mount();

        // A screen that replaces rows after load — the group runner swaps each
        // site's row in as its preview answers — says so, and the filters are
        // re-applied to what is there now. Without this the tools would go on
        // holding references to rows the DOM has thrown away.
        table.addEventListener('table-tools:rescan', () => tools.refresh());
    });

    document.querySelectorAll('[data-action-bar]').forEach(actionBar);
}

/* ------------------------------------------------------------------ sticky */

/**
 * Where a bar that pins to the top of the window has to stop.
 *
 * The app bar is a token; the scope bar is not, because it is not on every
 * page and its height follows its own content. Measured rather than assumed —
 * a hard-coded 37px is correct on exactly the screen it was measured on.
 */
function stickyOffset() {
    const root = document.documentElement;

    const apply = () => {
        const bar = document.querySelector('.scopebar');
        const height = bar ? Math.round(bar.getBoundingClientRect().height) : 0;
        root.style.setProperty('--sticky-top', `calc(var(--appbar-h) + ${height}px)`);
    };

    apply();
    window.addEventListener('resize', apply);
}

/**
 * A two-row head: the second row sticks under the first, at whatever height
 * the first turned out to be. Only the browser knows that number — the labels
 * wrap, and the grid's text size is the user's choice.
 */
function stickyHead(table) {
    const head = table.tHead;

    if (!head || head.rows.length < 2) return;

    const first = head.rows[0];
    const apply = () => {
        table.style.setProperty('--head-row-h', `${Math.round(first.getBoundingClientRect().height)}px`);
    };

    apply();

    if (window.ResizeObserver) new ResizeObserver(apply).observe(first);
    else window.addEventListener('resize', apply);
}

/* ------------------------------------------------------------------- table */

class TableTools {
    constructor(table) {
        this.table = table;
        this.head = table.tHead;
        this.body = table.tBodies[0];
        this.usable = Boolean(this.head && this.head.rows.length && this.body);

        if (!this.usable) return;

        this.headRow = this.head.rows[this.head.rows.length - 1];
        this.rows = Array.from(this.body.rows).filter((row) => isDataRow(row));
        this.order = this.rows.slice();
        this.sort = null;
        this.columns = this.readColumns();
        this.usable = this.columns.length > 0 && this.rows.length > 0;
    }

    /**
     * One entry per head cell that can carry a control. `.pick` is the tick
     * column and `data-no-tools` is the caller saying so; both keep their cell
     * in the filter row so the columns still line up.
     */
    readColumns() {
        return Array.from(this.headRow.cells).map((th, index) => {
            const skip = th.classList.contains('pick')
                || th.classList.contains('dg-actions-head')
                || th.hasAttribute('data-no-tools')
                || th.querySelector('a');

            const column = {
                index,
                th,
                label: th.textContent.trim(),
                skip,
                type: 'text',
                numeric: th.classList.contains('num'),
                query: '',
                op: 'eq',
                chosen: null,
            };

            if (!skip) column.type = this.inferType(column);

            return column;
        });
    }

    inferType(column) {
        if (column.numeric) return 'number';

        const sample = this.rows.slice(0, 80)
            .map((row) => this.text(row, column.index))
            .filter((value) => value !== '' && value !== NOTHING);

        if (sample.length === 0) return 'text';

        const dates = sample.filter((value) => /^\d{4}-\d{2}-\d{2}$/.test(value)).length;

        return dates / sample.length >= 0.8 ? 'date' : 'text';
    }

    /** The value a cell sorts and filters on. `data-sort` wins where a cell has one. */
    text(row, index) {
        const cell = row.cells[index];

        if (!cell) return '';
        if (cell.dataset.sort !== undefined) return cell.dataset.sort;

        return cell.textContent.replace(/\s+/g, ' ').trim();
    }

    /** Re-read the body after something replaced rows in it. */
    refresh() {
        this.rows = Array.from(this.body.rows).filter((row) => isDataRow(row));
        this.order = this.rows.slice();
        this.sort = null;
        this.apply();
    }

    mount() {
        this.buildSort();
        this.buildFilters();
        stickyHead(this.table);
        this.report(this.rows.length);
    }

    /* ------------------------------------------------------------- sorting */

    buildSort() {
        this.columns.forEach((column) => {
            if (column.skip) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tt-sort';
            button.textContent = column.label;
            button.append(Object.assign(document.createElement('span'), { className: 'tt-arrow' }));
            button.title = `Sort by ${column.label}`;

            column.th.textContent = '';
            column.th.append(button);

            button.addEventListener('click', () => this.toggleSort(column));
        });
    }

    /**
     * Ascending, descending, then back to the order the server sent — which is
     * itself an answer on these screens (a run's proposals arrive worst first)
     * and would otherwise be unreachable once anything had been clicked.
     */
    toggleSort(column) {
        if (!this.sort || this.sort.column !== column) this.sort = { column, direction: 1 };
        else if (this.sort.direction === 1) this.sort.direction = -1;
        else this.sort = null;

        this.columns.forEach((other) => {
            other.th.removeAttribute('aria-sort');
            const arrow = other.th.querySelector('.tt-arrow');
            if (arrow) arrow.textContent = '';
        });

        let ordered = this.order;

        if (this.sort) {
            const { direction } = this.sort;

            column.th.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending');
            const arrow = column.th.querySelector('.tt-arrow');
            if (arrow) arrow.textContent = direction === 1 ? '▲' : '▼';

            ordered = this.order.slice().sort((a, b) => this.compare(a, b, column) * direction);
        }

        // The rows move as blocks: a row that row-detail.js has expanded takes
        // its panel with it, or the drill ends up under somebody else's total.
        const fragment = document.createDocumentFragment();
        ordered.forEach((row) => block(row).forEach((node) => fragment.append(node)));
        this.body.append(fragment);
    }

    compare(a, b, column) {
        const left = this.text(a, column.index);
        const right = this.text(b, column.index);

        const empty = (value) => value === '' || value === NOTHING;

        // A missing figure sorts last in BOTH directions. It is not smaller
        // than everything, it is absent, and burying it at the top of a
        // descending sort hides the rows somebody is usually looking for.
        if (empty(left) && empty(right)) return 0;
        if (empty(left)) return 1;
        if (empty(right)) return -1;

        if (column.type === 'number') {
            const l = number(left);
            const r = number(right);

            if (l !== null && r !== null) return l - r;
        }

        return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
    }

    /* ------------------------------------------------------------ filtering */

    buildFilters() {
        const row = document.createElement('tr');
        row.className = 'tt-filters';

        this.columns.forEach((column) => {
            const cell = document.createElement('th');
            if (column.numeric) cell.className = 'num';

            if (!column.skip) this.buildFilterCell(cell, column);

            row.append(cell);
        });

        this.head.append(row);
    }

    /** Every column back to unfiltered, from one press. */
    clearAll() {
        this.columns.forEach((column) => {
            column.query = '';
            column.chosen = null;
            if (column.input) column.input.value = '';
            this.markColumn(column);
        });

        this.apply();
    }

    buildFilterCell(cell, column) {
        const wrap = document.createElement('div');
        wrap.className = 'tt-filter';

        if (column.type !== 'text') {
            const operator = document.createElement('select');
            operator.className = 'tt-op';
            operator.setAttribute('aria-label', `How to compare ${column.label}`);

            Object.entries({ eq: '=', ne: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤' })
                .forEach(([value, glyph]) => {
                    operator.append(new Option(glyph, value));
                });

            operator.addEventListener('change', () => {
                column.op = operator.value;
                if (column.query !== '') this.apply();
            });

            wrap.append(operator);
        }

        const input = document.createElement('input');
        input.type = column.type === 'date' ? 'date' : (column.type === 'number' ? 'number' : 'search');
        input.step = 'any';
        input.className = 'tt-q';
        input.placeholder = column.type === 'text' ? 'contains…' : '';
        input.setAttribute('aria-label', `Filter ${column.label}`);

        let timer = null;
        input.addEventListener('input', () => {
            column.query = input.value.trim();
            window.clearTimeout(timer);
            // A search box that re-hides two hundred rows on every keystroke
            // reads as a stutter; a hundred and twenty milliseconds reads as
            // instant and only runs once for a typed word.
            timer = window.setTimeout(() => { this.markColumn(column); this.apply(); }, 120);
        });

        column.input = input;
        wrap.append(input);

        const values = this.distinct(column);

        // The Excel list, where there is a list to give. A column of two
        // hundred distinct bank narratives is not a tick list, it is a search
        // box, and the search box is already there.
        if (values.length > 1 && values.length <= MAX_SET) {
            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'tt-pick';
            open.innerHTML = '<span aria-hidden="true">▾</span>';
            open.title = `Choose which ${column.label} values to show`;
            open.setAttribute('aria-label', `Choose which ${column.label} values to show`);
            open.addEventListener('click', () => this.openPanel(column, values, open));
            wrap.append(open);
        }

        cell.append(wrap);
    }

    distinct(column) {
        const seen = new Set();

        this.rows.forEach((row) => seen.add(this.text(row, column.index) || NOTHING));

        return Array.from(seen).sort((a, b) => {
            if (column.type === 'number') {
                const l = number(a);
                const r = number(b);
                if (l !== null && r !== null) return l - r;
            }
            return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
        });
    }

    /**
     * The value list, in the BODY rather than in the cell.
     *
     * `.table-scroll` is a scroll container on both axes — that is what makes
     * the head stick — and a panel drawn inside it is clipped by it. So it is
     * positioned against the window from the button's own rectangle, and it
     * follows that rectangle while anything scrolls.
     */
    openPanel(column, values, anchor) {
        closePanel();

        const panel = document.createElement('div');
        panel.className = 'tt-panel';

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'tt-panel-search';
        search.placeholder = 'Search these values…';
        search.setAttribute('aria-label', `Search ${column.label} values`);

        const list = document.createElement('div');
        list.className = 'tt-panel-list';

        const all = document.createElement('label');
        all.className = 'tt-panel-all';
        const allBox = document.createElement('input');
        allBox.type = 'checkbox';
        allBox.checked = column.chosen === null;
        all.append(allBox, document.createTextNode(' (Select all)'));

        const boxes = values.map((value) => {
            const label = document.createElement('label');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.value = value;
            box.checked = column.chosen === null || column.chosen.has(value);

            const text = document.createElement('span');
            text.textContent = value;
            text.title = value;

            label.append(box, text);
            list.append(label);

            return box;
        });

        const readBoxes = () => {
            const ticked = boxes.filter((box) => box.checked);

            // Everything ticked is not a filter, it is the absence of one —
            // and storing it as one would leave the column marked as filtered
            // for no reason.
            column.chosen = ticked.length === boxes.length ? null : new Set(ticked.map((box) => box.value));

            allBox.checked = ticked.length === boxes.length;
            allBox.indeterminate = ticked.length > 0 && ticked.length < boxes.length;

            this.markColumn(column);
            this.apply();
        };

        allBox.addEventListener('change', () => {
            boxes.forEach((box) => { if (!box.closest('label').hidden) box.checked = allBox.checked; });
            readBoxes();
        });

        boxes.forEach((box) => box.addEventListener('change', readBoxes));

        search.addEventListener('input', () => {
            const needle = search.value.trim().toLowerCase();
            boxes.forEach((box) => {
                box.closest('label').hidden = needle !== '' && !box.value.toLowerCase().includes(needle);
            });
        });

        const foot = document.createElement('div');
        foot.className = 'tt-panel-foot';

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn-ghost';
        clear.textContent = 'Clear this column';
        clear.addEventListener('click', () => {
            column.chosen = null;
            column.query = '';
            if (column.input) column.input.value = '';
            this.markColumn(column);
            this.apply();
            closePanel();
        });

        const done = document.createElement('button');
        done.type = 'button';
        done.className = 'btn sm';
        done.textContent = 'Done';
        done.addEventListener('click', closePanel);

        foot.append(clear, done);
        panel.append(search, all, list, foot);
        document.body.append(panel);

        const place = () => {
            const box = anchor.getBoundingClientRect();
            const width = Math.min(280, window.innerWidth - 16);
            const left = Math.min(Math.max(8, box.left), window.innerWidth - width - 8);

            panel.style.width = `${width}px`;
            panel.style.left = `${left}px`;
            panel.style.top = `${box.bottom + 4}px`;
            panel.style.maxHeight = `${Math.max(160, window.innerHeight - box.bottom - 20)}px`;
        };

        place();
        search.focus();

        openPanelState = { panel, place, anchor };
    }

    /** A filtered column says so on the head, not only in the box. */
    markColumn(column) {
        const active = column.query !== '' || column.chosen !== null;
        column.th.classList.toggle('is-filtered', active);
        const cell = this.head.querySelector('.tt-filters')?.cells[column.index];
        if (cell) cell.classList.toggle('is-filtered', active);
    }

    matches(row) {
        return this.columns.every((column) => {
            if (column.skip) return true;

            const value = this.text(row, column.index);

            if (column.chosen !== null && !column.chosen.has(value || NOTHING)) return false;
            if (column.query === '') return true;

            if (column.type === 'text') {
                return value.toLowerCase().includes(column.query.toLowerCase());
            }

            if (column.type === 'number') {
                const left = number(value);
                const right = number(column.query);

                return left !== null && right !== null && test(left, right, column.op);
            }

            // Dates compare as ISO strings, which is the one format where
            // that is the same answer as comparing the dates.
            return value !== '' && test(value, column.query, column.op);
        });
    }

    apply() {
        let visible = 0;

        this.rows.forEach((row) => {
            const show = this.matches(row);

            block(row).forEach((node) => node.classList.toggle('tt-out', !show));
            enable(row, show);

            if (show) visible += 1;
        });

        this.report(visible);
    }

    /**
     * What the table says about itself once a filter is on. The footer count
     * came from the server and is now wrong; the empty state was never
     * rendered at all, because the server had rows to show.
     */
    report(visible) {
        const total = this.rows.length;
        const filtered = visible !== total;
        const block_ = this.table.closest('.table-block');

        // On the element, not only in the event. The confirmation dialog has to
        // read this at the moment of a submit — it cannot wait for the next
        // table-tools:change, and it must not have to subscribe to a table it
        // may not even own. `data-confirm-filtered` on a form names the table
        // and reads these two.
        this.table.dataset.ttHidden = String(total - visible);
        this.table.dataset.ttTotal = String(total);

        if (block_) {
            const count = block_.querySelector('.table-count');

            if (count) {
                if (this.originalCount === undefined) this.originalCount = count.textContent;
                count.textContent = filtered
                    ? `Showing ${visible} of ${total} — filtered`
                    : this.originalCount;
            }

            let empty = block_.querySelector('[data-tt-empty]');

            if (!empty) {
                empty = document.createElement('p');
                empty.className = 'empty-state table-empty';
                empty.setAttribute('data-tt-empty', '');
                empty.textContent = 'No row matches these column filters. ';

                // The way out is offered where the dead end is. A reader who
                // has narrowed four columns to nothing should not have to find
                // and undo all four to get their rows back.
                const undo = document.createElement('button');
                undo.type = 'button';
                undo.className = 'btn-ghost';
                undo.textContent = 'Clear the filters';
                undo.addEventListener('click', () => this.clearAll());

                empty.append(undo);
                empty.hidden = true;
                this.table.closest('.table-scroll')?.after(empty);
            }

            empty.hidden = visible !== 0;
        }

        this.table.dispatchEvent(new CustomEvent('table-tools:change', {
            bubbles: true,
            detail: { visible, total, filtered },
        }));
    }
}

/* ------------------------------------------------------------- action bar */

/**
 * The count on a commit button, kept true.
 *
 * It follows two things at once: what is ticked, and what a filter has taken
 * out of the submission. Both change the number of rows the press will stamp,
 * and a button that says 138 while it is about to stamp 12 is worse than a
 * button with no number on it.
 */
function actionBar(bar) {
    const table = document.getElementById(bar.dataset.actionBar);

    if (!table) return;

    const buttons = Array.from(bar.querySelectorAll('[data-count-verb]'));
    const scope = bar.querySelector('[data-action-bar-scope]');

    if (buttons.length === 0 && !scope) return;

    let hidden = 0;

    const recount = () => {
        const ticked = table.querySelectorAll('[data-check]:checked:not(:disabled)').length;

        buttons.forEach((button) => {
            const noun = ticked === 1
                ? button.dataset.countNoun
                : (button.dataset.countPlural || `${button.dataset.countNoun}s`);

            button.textContent = `${button.dataset.countVerb} ${ticked} ${noun}`;
            button.disabled = ticked === 0;
        });

        if (scope) {
            scope.hidden = hidden === 0;
            scope.textContent = `${hidden} ${hidden === 1 ? 'row is' : 'rows are'} hidden by a column filter `
                + 'and will not be included';
        }
    };

    table.addEventListener('change', recount);
    table.addEventListener('table-tools:change', (event) => {
        hidden = event.detail.total - event.detail.visible;
        recount();
    });

    recount();
}

/* ------------------------------------------------------------------ shared */

let openPanelState = null;

function closePanel() {
    if (!openPanelState) return;

    openPanelState.panel.remove();
    openPanelState = null;
}

document.addEventListener('click', (event) => {
    if (!openPanelState) return;
    if (event.target.closest('.tt-panel') || event.target === openPanelState.anchor) return;
    if (openPanelState.anchor.contains(event.target)) return;

    closePanel();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closePanel();
});

// Capture, because the scrolling happens inside .table-scroll and a scroll
// event on an element does not bubble.
document.addEventListener('scroll', () => { if (openPanelState) openPanelState.place(); }, true);
window.addEventListener('resize', () => { if (openPanelState) openPanelState.place(); });

/** A data row, not a drilled panel and not the grid's totals line. */
function isDataRow(row) {
    return !row.classList.contains('row-detail') && !row.classList.contains('dg-total');
}

/** A row and whatever row-detail.js has opened under it. */
function block(row) {
    const nodes = [row];

    let next = row.nextElementSibling;

    while (next && next.classList.contains('row-detail')) {
        nodes.push(next);
        next = next.nextElementSibling;
    }

    return nodes;
}

/**
 * Take a hidden row out of the submission, and put it back intact.
 *
 * `disabled` rather than unticked: a filter is a way of looking, and clearing
 * it has to give back exactly the selection that was there before. A control
 * the caller disabled for its own reasons is left alone — the marker says
 * which ones are ours.
 */
function enable(row, on) {
    row.querySelectorAll('input, select, textarea, button').forEach((control) => {
        if (on) {
            if (control.dataset.ttOff === undefined) return;
            delete control.dataset.ttOff;
            control.disabled = false;
            return;
        }

        if (control.disabled) return;
        control.dataset.ttOff = '';
        control.disabled = true;
    });
}

/** `R1 234.50`, `-R1 234.50`, `1 234 567.89` — the formats App\Support\Format writes. */
function number(text) {
    if (text === '' || text === NOTHING) return null;

    const cleaned = String(text).replace(/[^0-9.\-]/g, '');

    if (cleaned === '' || cleaned === '-' || cleaned === '.') return null;

    const value = Number(cleaned);

    return Number.isFinite(value) ? value : null;
}

function test(left, right, op) {
    switch (op) {
        case 'ne': return left !== right;
        case 'gt': return left > right;
        case 'gte': return left >= right;
        case 'lt': return left < right;
        case 'lte': return left <= right;
        default: return left === right;
    }
}
