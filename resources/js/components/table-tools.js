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
 *   <table data-tt-page="50">                     — cut the rows into pages
 *   <th data-no-tools>                            — no sort, no filter
 *   <th data-tt-pills="Balanced"                  — this column as a pill strip
 *       data-tt-pill-order="A|B|C">                 above the table, opening on
 *                                                   that value
 *   <td data-sort="2026-09-08">8 Sep</td>         — sort and filter on this
 *                                                   instead of the rendered text
 *   <td data-sort="Balanced" data-tone="good">    — and the chip tone its pill
 *                                                   takes
 *
 * PAGING IS A WAY OF LOOKING, NOT A SCOPE, and that is the whole reason it is
 * allowed on a screen that stamps from tick boxes. Every row is in the
 * document and every ticked row is in the submission, whichever page it is
 * sitting on; the button's count is of the whole filtered set, not of the page.
 * A FILTER is the other thing — it takes rows out of the submission, as above.
 * Confusing the two is how a clerk would come to stamp four hundred rows they
 * never saw, or to miss three hundred they meant to.
 */

const NOTHING = '—';
const MAX_SET = 400;

export default function tableTools(root = document) {
    stickyOffset();

    // `root` and the ready marks are for a fragment that arrives after load —
    // a recon centre tab — handed in by window.Agora.hydrate(). A table is
    // only ever wired once, so hydrating twice cannot double its handlers.
    const fresh = (selector, mark) => Array.from(root.querySelectorAll(`${selector}:not([${mark}])`))
        .map((el) => { el.setAttribute(mark, ''); return el; });

    // Every .dt, not only the ones with tools: the data grid's own filter row
    // is a second head row too, and it has been sliding under the first since
    // the day the scroll container got a height.
    fresh('table.dt', 'data-sticky-ready').forEach(stickyHead);

    /*
     * BEFORE the tables mount, not after.
     *
     * A pill strip can arrive with a default scope on, so the first `apply()`
     * is already hiding rows — and it dispatches the only `table-tools:change`
     * that will be sent until somebody touches a control. An action bar wired
     * up afterwards missed it, and sat there saying nothing while a scope held
     * 68 of 144 shifts out of the press. Wiring it first costs one recount
     * against an unfiltered table and then it hears the real one.
     */
    fresh('[data-action-bar]', 'data-action-bar-ready').forEach(actionBar);

    fresh('table[data-table-tools]', 'data-table-tools-ready').forEach((table) => {
        const tools = new TableTools(table);

        if (!tools.usable) return;

        tools.mount();

        // A screen that replaces rows after load — the group runner swaps each
        // site's row in as its preview answers — says so, and the filters are
        // re-applied to what is there now. Without this the tools would go on
        // holding references to rows the DOM has thrown away.
        table.addEventListener('table-tools:rescan', () => tools.refresh());
    });
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
        // The order the rows are in ON SCREEN. `order` is the order the server
        // sent and stops being the reader's order the moment a column is
        // sorted — and paging has to cut the reader's order, or page two of a
        // freshly sorted table is the second fifty of the previous one.
        this.view = this.rows.slice();
        this.sort = null;
        this.pageSize = readPageSize(this.table);
        this.page = 1;
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
                // A pilled column is filtered ABOVE the table rather than in
                // the filter row — same filter, different control.
                pills: th.dataset.ttPills !== undefined,
                pillDefault: (th.dataset.ttPills || '').trim(),
                pillOrder: (th.dataset.ttPillOrder || '').split('|')
                    .map((value) => value.trim()).filter(Boolean),
                pillButtons: [],
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
        this.view = this.rows.slice();
        this.sort = null;
        this.page = 1;
        this.apply();
    }

    mount() {
        this.buildSort();
        this.buildFilters();
        this.buildPills();
        this.buildPager();
        stickyHead(this.table);
        // Not `report(total)`: a pill strip can arrive with a default scope on,
        // so the first paint has to be a real pass over the rows rather than an
        // assertion that nothing is hidden yet.
        this.apply();
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

        // Re-sorting is re-paging: the pages are cuts of the order on screen,
        // and that order has just changed. Back to page one, because page four
        // of a new sort is somewhere nobody asked to be.
        this.view = ordered.slice();
        this.page = 1;
        this.apply();
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
            this.markPills(column);
        });

        this.refilter();
    }

    /**
     * A filter changed. Paging starts again from the first page — staying on
     * page seven of a set that is now two pages long shows an empty table and
     * reads as a broken screen.
     */
    refilter() {
        this.page = 1;
        this.apply();
    }

    buildFilterCell(cell, column) {
        // A pilled column is already filtered, above the table. A second
        // control for the same column here would be a second answer to one
        // question; the cell stays so the columns still line up.
        if (column.pills) {
            cell.classList.add('tt-pilled');
            return;
        }

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
                if (column.query !== '') this.refilter();
            });

            wrap.append(operator);
        }

        const input = document.createElement('input');
        input.type = column.type === 'date' ? 'date' : (column.type === 'number' ? 'number' : 'search');
        input.step = 'any';
        input.className = 'tt-q';
        // Short, because the box is now sized to the COLUMN rather than to the
        // browser's twenty-character default — "contains…" rendered as
        // "conta" in a narrow one. The full semantics live in the title and
        // the aria-label, which are not truncated by anything.
        input.placeholder = column.type === 'text' ? 'find…' : '';
        input.title = column.type === 'text'
            ? `Show rows where ${column.label} contains this`
            : `Compare ${column.label}`;
        input.setAttribute('aria-label', `Filter ${column.label}`);

        let timer = null;
        input.addEventListener('input', () => {
            column.query = input.value.trim();
            window.clearTimeout(timer);
            // A search box that re-hides two hundred rows on every keystroke
            // reads as a stutter; a hundred and twenty milliseconds reads as
            // instant and only runs once for a typed word.
            timer = window.setTimeout(() => { this.markColumn(column); this.refilter(); }, 120);
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
            this.markPills(column);
            this.refilter();
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
            this.markPills(column);
            this.refilter();
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

    /* --------------------------------------------------------------- pills */

    /**
     * A column shown as a row of pills above the table instead of as a tick
     * list buried in the filter row.
     *
     * Asked for on the stock recon proposals, 22 Sep 2026: the outcome column's
     * Excel list answered the question but hid it behind a press, and the
     * handful of states it holds are the first cut anybody makes on a run. As
     * pills they are visible, counted, and one click each.
     *
     * It is the SAME filter — `column.chosen`, the thing the tick list sets —
     * so everything that follows from a filter still follows: the heading is
     * marked, the hidden rows leave the submission, and the action bar says so.
     * A pill that merely hid rows while leaving them in the commit would be the
     * dangerous half of a filter with none of the honest half.
     */
    buildPills() {
        this.columns.filter((column) => column.pills).forEach((column) => this.buildPillStrip(column));
    }

    buildPillStrip(column) {
        const counts = new Map();
        const tones = new Map();

        this.rows.forEach((row) => {
            const value = this.text(row, column.index) || NOTHING;

            counts.set(value, (counts.get(value) || 0) + 1);

            if (!tones.has(value)) tones.set(value, row.cells[column.index]?.dataset.tone || 'neutral');
        });

        /*
         * The declared order first, so a run that happens not to contain a
         * state does not reshuffle a strip somebody has learned the shape of —
         * a zero is rendered, dimmed, rather than left out. Then anything the
         * data holds that the caller did not name.
         */
        const named = column.pillOrder.filter((value, i, all) => all.indexOf(value) === i);
        const rest = Array.from(counts.keys())
            .filter((value) => !named.includes(value))
            .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

        const strip = document.createElement('div');
        strip.className = 'tt-pills';
        strip.setAttribute('role', 'group');
        strip.setAttribute('aria-label', `Filter by ${column.label}`);

        const caption = document.createElement('span');
        caption.className = 'tt-pills-label';
        caption.textContent = column.label;
        strip.append(caption);

        column.pillButtons = [];

        const add = (value, label, tone, count) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `tt-pill chip ${tone} tone-${tone}`;
            button.disabled = count === 0;

            const dot = document.createElement('span');
            dot.className = 'dot';
            dot.setAttribute('aria-hidden', 'true');

            const text = document.createElement('span');
            text.className = 'tt-pill-t';
            text.textContent = label;

            const n = document.createElement('span');
            n.className = 'tt-pill-n';
            n.textContent = String(count);

            button.append(dot, text, n);
            button.title = count === 1 ? `1 shift · ${label}` : `${count} shifts · ${label}`;
            button.addEventListener('click', () => this.choosePill(column, value));

            strip.append(button);
            column.pillButtons.push({ button, value });
        };

        add(null, 'All', 'neutral', this.rows.length);
        named.concat(rest).forEach((value) => add(value, value, tones.get(value) || 'neutral', counts.get(value) || 0));

        (this.table.closest('.table-block') || this.table.parentElement).prepend(strip);

        /*
         * The pill the screen opens on. A default this particular run has no
         * rows for would open the table empty, which reads as a broken screen
         * rather than as a scope — so it quietly falls back to All.
         */
        if (column.pillDefault && (counts.get(column.pillDefault) || 0) > 0) {
            column.chosen = new Set([column.pillDefault]);
        }

        this.markColumn(column);
        this.markPills(column);
    }

    choosePill(column, value) {
        // One pill at a time. The tick list is still the way to say "these
        // three"; a pill strip is the way to say "that one", which is the
        // question it was asked for.
        column.chosen = value === null ? null : new Set([value]);

        this.markColumn(column);
        this.markPills(column);
        this.refilter();
    }

    markPills(column) {
        column.pillButtons.forEach(({ button, value }) => {
            const on = value === null
                ? column.chosen === null
                : column.chosen !== null && column.chosen.size === 1 && column.chosen.has(value);

            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', String(on));
        });
    }

    /* --------------------------------------------------------------- paging */

    /**
     * The pager, under the rows. Built once; its numbers are redrawn on every
     * pass, because how many pages there are is a function of what the filters
     * left behind.
     */
    buildPager() {
        if (!this.pageSize) return;

        const nav = document.createElement('nav');
        nav.className = 'tt-pager';
        nav.setAttribute('aria-label', `Pages of ${this.table.id || 'this table'}`);

        this.pagerPrev = pagerButton('‹ Prev', () => this.goTo(this.page - 1));
        this.pagerNext = pagerButton('Next ›', () => this.goTo(this.page + 1));

        this.pagerPages = document.createElement('div');
        this.pagerPages.className = 'tt-pager-pages';

        const size = document.createElement('label');
        size.className = 'tt-pager-size';

        const select = document.createElement('select');
        const options = PAGE_SIZES.includes(this.pageSize) ? PAGE_SIZES : [this.pageSize, ...PAGE_SIZES].sort((a, b) => a - b);
        options.forEach((n) => select.append(new Option(String(n), String(n))));
        // "All" is not a dare — it is the way back to the screen this table was
        // before it was paged, which somebody printing or scanning wants.
        select.append(new Option('All', '0'));
        select.value = String(this.pageSize);
        select.setAttribute('aria-label', 'Rows per page');
        select.addEventListener('change', () => {
            this.pageSize = Number(select.value) || 0;
            this.page = 1;
            this.apply();
        });

        size.append(document.createTextNode('Rows '), select);
        nav.append(this.pagerPrev, this.pagerPages, this.pagerNext, size);

        this.pager = nav;
        this.table.closest('.table-scroll')?.after(nav);
    }

    goTo(page) {
        this.page = page;
        this.apply();
        this.scrollToTop();
    }

    /**
     * Page two starts at the top of page two, not eighty rows into it — and
     * not underneath the furniture either.
     *
     * The pager is under the last row, so pressing Next leaves the reader at
     * the bottom of the table with a new page of rows above them. Scrolling
     * back is right; scrolling the block to y = 0 is not, because the app bar,
     * the scope bar and the commit bar are all pinned over that space and the
     * table's own sticky head lands behind them. A page of rows with no column
     * headings on it is what that looks like, and it looks like a bug.
     */
    scrollToTop() {
        const block = this.table.closest('.table-block');

        if (!block) return;

        this.table.closest('.table-scroll')?.scrollTo({ top: 0 });

        // Measured, and only the bars that are actually pinned right now: the
        // action bar is static on a phone, and counting it there would leave a
        // gap where the rows should be.
        const chrome = Array.from(document.querySelectorAll('.appbar, .scopebar, .action-bar'))
            .filter((el) => ['fixed', 'sticky'].includes(getComputedStyle(el).position))
            .reduce((total, el) => total + el.getBoundingClientRect().height, 0);

        const top = block.getBoundingClientRect().top + window.scrollY - chrome - 8;

        window.scrollTo({ top: Math.max(0, top) });
    }

    /**
     * Hide everything outside the current page — WITHOUT disabling it. This is
     * the line between paging and filtering, and it is the reason a paged
     * commit form is not a form that stamps rows nobody looked at: a tick on
     * page three is still ticked, still counted on the button, and still in the
     * POST. See the head of this file.
     */
    paginate(rows) {
        if (!this.pageSize) {
            rows.forEach((row) => block(row).forEach((node) => node.classList.remove('tt-page-out')));
            this.drawPager(1, rows.length, 0, rows.length);

            return;
        }

        const pages = Math.max(1, Math.ceil(rows.length / this.pageSize));

        this.page = Math.min(Math.max(1, this.page), pages);

        const first = (this.page - 1) * this.pageSize;
        const last = Math.min(first + this.pageSize, rows.length);

        rows.forEach((row, index) => {
            const on = index >= first && index < last;

            block(row).forEach((node) => node.classList.toggle('tt-page-out', !on));
        });

        this.drawPager(pages, rows.length, first, last);
    }

    drawPager(pages, total, first, last) {
        if (!this.pager) return;

        // Nothing worth a control: a table shorter than the smallest page size
        // cannot be paged into anything, and a pager on it is furniture.
        this.pager.hidden = total <= PAGE_SIZES[0];

        this.pagerPrev.disabled = this.page <= 1;
        this.pagerNext.disabled = this.page >= pages;

        this.pagerPages.textContent = '';

        pageNumbers(this.page, pages).forEach((n) => {
            if (n === null) {
                const gap = document.createElement('span');
                gap.className = 'tt-pager-gap';
                gap.textContent = '…';
                this.pagerPages.append(gap);

                return;
            }

            const button = pagerButton(String(n), () => this.goTo(n));
            button.classList.toggle('is-on', n === this.page);
            button.setAttribute('aria-current', n === this.page ? 'page' : 'false');
            this.pagerPages.append(button);
        });

        this.pager.dataset.range = total === 0 ? '0' : `${first + 1}–${last} of ${total}`;
    }

    apply() {
        const passing = [];

        this.view.forEach((row) => {
            const show = this.matches(row);

            block(row).forEach((node) => node.classList.toggle('tt-out', !show));
            enable(row, show);

            if (show) passing.push(row);
            // A row the filter took out is not on any page, so it must not
            // carry a page class into the next pass and back out of one.
            else block(row).forEach((node) => node.classList.remove('tt-page-out'));
        });

        this.paginate(passing);
        this.report(passing.length);
    }

    /**
     * The footer line. Three things can be true at once — rows hidden by a
     * filter, rows on another page, and the count the server printed — and the
     * sentence has to say which is which. "Showing 1–50 of 312 — filtered from
     * 940" is the whole state in one line.
     */
    countText(visible, total, filtered) {
        if (!this.pageSize || visible <= this.pageSize) {
            return filtered ? `Showing ${visible} of ${total} — filtered` : this.originalCount;
        }

        const first = (this.page - 1) * this.pageSize;
        const last = Math.min(first + this.pageSize, visible);
        const shown = `Showing ${first + 1}–${last} of ${visible}`;

        return filtered ? `${shown} — filtered from ${total}` : shown;
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
                count.textContent = this.countText(visible, total, filtered);
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

const PAGE_SIZES = [25, 50, 100, 250];

/** `data-tt-page="50"`. Absent, zero or nonsense means no paging at all. */
function readPageSize(table) {
    const raw = Number(table.dataset.ttPage);

    return Number.isFinite(raw) && raw > 0 ? Math.floor(raw) : 0;
}

function pagerButton(label, onClick) {
    const button = document.createElement('button');

    button.type = 'button';
    button.className = 'tt-pager-btn';
    button.textContent = label;
    button.addEventListener('click', onClick);

    return button;
}

/**
 * First, last, and the three around the current one — `null` where a run of
 * pages was left out. Nine hundred rows is eighteen pages and eighteen buttons
 * is a second navigation bar nobody reads.
 */
function pageNumbers(page, pages) {
    const wanted = [];
    const push = (n) => { if (n >= 1 && n <= pages && !wanted.includes(n)) wanted.push(n); };

    push(1);
    push(2);
    for (let n = page - 1; n <= page + 1; n += 1) push(n);
    push(pages - 1);
    push(pages);

    wanted.sort((a, b) => a - b);

    const out = [];

    wanted.forEach((n, i) => {
        if (i > 0 && n - wanted[i - 1] > 1) out.push(null);
        out.push(n);
    });

    return out;
}

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
