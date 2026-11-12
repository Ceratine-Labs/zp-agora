/** Personal layouts for ordinary tables. Grid tables have their own chooser. */
export default function tableLayout(root = document) {
    const batch = [];
    root.querySelectorAll('.table-block[data-table-layout-url]:not([data-table-layout-ready])').forEach((block) => {
        block.dataset.tableLayoutReady = '';
        if (block.closest('[data-grid]')) return;
        const table = block.querySelector(':scope > .table-scroll > table.dt');
        if (!table || !table.tHead || !table.tHead.rows.length) return;
        const layout = new TableLayout(block, table);
        if (block.dataset.tableLayoutReadUrl) batch.push(layout);
        else layout.mount().catch((error) => console.error('Table layout failed to initialize.', error));
    });
    for (let i = 0; i < batch.length; i += 100) {
        loadBatch(batch.slice(i, i + 100)).catch((error) => console.error('Table layouts failed to initialize.', error));
    }
}

async function loadBatch(layouts) {
    const entries = layouts.map((layout) => {
        layout.prepare();
        const url = new URL(layout.url, window.location.href);
        return { key: url.pathname.split('/').pop(), signature: url.searchParams.get('signature') };
    });
    try {
        const response = await fetch(layouts[0].block.dataset.tableLayoutReadUrl, {
            method: 'POST', headers: layouts[0].headers(), credentials: 'same-origin',
            body: JSON.stringify({ tables: entries }),
        });
        if (!response.ok) throw new Error('Could not load layouts');
        const { states } = await response.json();
        layouts.forEach((layout, index) => layout.finish(states[entries[index].key] || {}));
    } catch (_) {
        layouts[0].notice('Your table layouts could not be loaded');
    }
}

class TableLayout {
    constructor(block, table) {
        this.block = block;
        this.table = table;
        this.head = Array.from(table.tHead.rows).filter((row) => !row.classList.contains('tt-filters')).at(-1);
        this.columns = Array.from(this.head.cells);
        this.url = block.dataset.tableLayoutUrl;
        this.scroll = table.parentElement;
        this.pending = Promise.resolve();
        this.timer = null;
        this.order = this.columns.map((_, i) => i);
        this.hidden = [];
        this.widths = {};
        this.textSize = 'normal';
        this.height = 'normal';
        const rows = Array.from(table.rows).filter((row) => !row.classList.contains('row-detail'));
        this.canColumns = this.columns.length > 1 && this.columns.length <= 100
            && this.columns.every((cell) => cell.colSpan === 1 && cell.rowSpan === 1)
            && rows.every((row) => Array.from(row.cells).every((cell) => cell.rowSpan === 1));
        // A spanning subtotal or group header must remain over its original
        // columns. Hiding and resizing work, but moving across such a span
        // would misrepresent the figures, so only flat tables can reorder.
        this.canReorder = this.canColumns && rows.every((row) =>
            (row.cells.length === 1 && row.cells[0].colSpan === this.columns.length)
            || (row.cells.length === this.columns.length && Array.from(row.cells).every((cell) => cell.colSpan === 1)));
    }

    async mount() {
        if (!this.columns.length) return;
        this.prepare();
        try {
            const response = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Could not load layout');
            const { state } = await response.json();
            this.finish(state || {});
        } catch (_) {
            this.notice('Your table layout could not be loaded');
        }
    }

    prepare() {
        if (!this.columns.length) return;
        this.labelCells();
        this.buildControls();
        this.resizeColumns();
        // Restore the column order before table-tools reads replacement rows.
        this.table.addEventListener('table-tools:rescan', () => {
            this.labelCells();
            const visible = this.hidden.filter((index) => !this.locked(index));
            const changed = visible.length !== this.hidden.length;
            this.hidden = visible; this.apply();
            if (changed) { this.draw(); this.save(); }
        }, { capture: true });
        this.table.addEventListener('table-tools:change', () => {
            const visible = this.hidden.filter((index) => !this.locked(index));
            if (visible.length !== this.hidden.length) {
                this.hidden = visible; this.apply(); this.draw(); this.save();
            }
            this.panel.querySelectorAll('[data-tl-choice]').forEach((box) => {
                box.disabled = this.locked(Number(box.dataset.tlChoice));
                box.title = box.disabled ? 'Columns with entry controls or active filters must remain visible.' : '';
            });
        });
        if (this.canColumns && window.MutationObserver) {
            const observer = new MutationObserver(() => this.sizeDetailRows());
            Array.from(this.table.tBodies).forEach((body) => observer.observe(body, { childList: true }));
        }
    }

    finish(state) {
        if (!this.columns.length) return;
        this.restore(state);
        this.draw();
        this.details.inert = false;
    }

    labelCells() {
        if (!this.canColumns) return;
        Array.from(this.table.rows).forEach((row) => {
            if (row.classList.contains('row-detail')) return;
            let index = 0;
            Array.from(row.cells).forEach((cell) => {
                if (cell.dataset.tlColumn === undefined) {
                    cell.dataset.tlColumn = String(index);
                    cell.dataset.tlSpan = String(cell.colSpan);
                }
                index += Number(cell.dataset.tlSpan || cell.colSpan);
            });
        });
        this.inputColumns = new Set();
        this.columns.forEach((th, index) => {
            if (th.querySelector('input, select, textarea')) this.inputColumns.add(index);
        });
        Array.from(this.table.tBodies).forEach((body) => body.querySelectorAll('input, select, textarea').forEach((input) => {
            const cell = input.closest('[data-tl-column]');
            if (cell && cell.closest('table') === this.table) this.inputColumns.add(Number(cell.dataset.tlColumn));
        }));
    }

    locked(index) {
        const th = this.columns[index];
        return Boolean(this.inputColumns?.has(index) || th.classList.contains('pick') || th.classList.contains('is-filtered'));
    }

    resizeColumns() {
        if (!this.canColumns) return;
        this.columns.forEach((th, index) => {
            const handle = document.createElement('span');
            handle.className = 'tl-resize';
            handle.title = 'Drag to resize column';
            handle.tabIndex = 0;
            handle.setAttribute('role', 'separator');
            handle.setAttribute('aria-label', `Resize ${th.textContent.trim() || 'column'}`);
            handle.setAttribute('aria-orientation', 'vertical');
            th.append(handle);
            handle.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
                event.preventDefault(); event.stopPropagation();
                const width = this.widths[index] || Math.round(th.getBoundingClientRect().width);
                this.widths[index] = Math.max(60, Math.min(640, width + (event.key === 'ArrowRight' ? 10 : -10)));
                this.apply(); this.save();
            });
            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault(); event.stopPropagation();
                const start = th.getBoundingClientRect().width;
                const x = event.clientX;
                handle.setPointerCapture(event.pointerId);
                const move = (e) => {
                    this.widths[index] = Math.max(60, Math.min(640, Math.round(start + e.clientX - x)));
                    this.apply();
                };
                const stop = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', stop);
                    handle.removeEventListener('pointercancel', stop);
                    this.save();
                };
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', stop);
                handle.addEventListener('pointercancel', stop);
            });
        });
    }

    buildControls() {
        this.details = document.createElement('details');
        this.details.className = 'tl-chooser';
        this.details.inert = true;
        const summary = document.createElement('summary');
        summary.className = 'btn-ghost';
        summary.textContent = 'Table layout';
        this.details.append(summary);
        this.panel = document.createElement('div');
        this.panel.className = 'tl-panel';
        this.details.append(this.panel);
        this.block.insertBefore(this.details, this.scroll);
    }

    restore(state) {
        const valid = (value) => Number.isInteger(value) && value >= 0 && value < this.columns.length;
        if (this.canReorder && Array.isArray(state.order)) {
            const order = [...new Set(state.order.filter(valid))];
            this.order = [...order, ...this.order.filter((i) => !order.includes(i))];
        }
        if (Array.isArray(state.hidden)) this.hidden = [...new Set(state.hidden.filter((i) => valid(i) && !this.locked(i)))];
        if (state.widths && typeof state.widths === 'object') {
            Object.entries(state.widths).forEach(([key, width]) => {
                if (valid(Number(key)) && Number.isInteger(width) && width >= 60 && width <= 640) this.widths[key] = width;
            });
        }
        if (['compact', 'normal', 'large'].includes(state.text_size)) this.textSize = state.text_size;
        if (['compact', 'normal', 'large'].includes(state.height)) this.height = state.height;
        this.apply();
    }

    draw() {
        this.panel.replaceChildren();
        if (this.canColumns) {
            const list = document.createElement('ul');
            list.className = 'tl-list';
            this.order.forEach((index, position) => {
                const th = this.columns[index];
                const li = document.createElement('li');
                const label = document.createElement('label');
                const box = document.createElement('input');
                box.type = 'checkbox';
                box.dataset.tlChoice = String(index);
                // Never hide a selection/input column: a hidden tick is still
                // submitted, which would make a financial action misleading.
                const locked = this.locked(index);
                box.disabled = Boolean(locked);
                if (locked) box.title = 'Columns with entry controls or active filters must remain visible.';
                box.checked = locked || !this.hidden.includes(index);
                box.addEventListener('change', () => {
                    if (!box.checked && this.locked(index)) {
                        box.checked = true;
                        this.notice('Clear the column filter before hiding it');
                        return;
                    }
                    this.hidden = box.checked ? this.hidden.filter((n) => n !== index) : [...this.hidden, index];
                    this.apply(); this.save();
                });
                label.append(box, document.createTextNode(` ${th.textContent.trim() || `Column ${index + 1}`}`));
                li.append(label);
                for (const [direction, symbol] of (this.canReorder ? [[-1, '↑'], [1, '↓']] : [])) {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'dg-nudge'; button.textContent = symbol;
                    button.setAttribute('aria-label', `Move ${th.textContent.trim() || 'column'} ${direction < 0 ? 'earlier' : 'later'}`);
                    button.disabled = position + direction < 0 || position + direction >= this.order.length;
                    button.addEventListener('click', () => {
                        const next = position + direction;
                        [this.order[position], this.order[next]] = [this.order[next], this.order[position]];
                        this.apply(); this.draw(); this.save();
                    });
                    li.append(button);
                }
                list.append(li);
            });
            this.panel.append(list);
            if (!this.canReorder) {
                const note = document.createElement('p');
                note.textContent = 'Grouped columns keep their order.';
                this.panel.append(note);
            }
        } else {
            const note = document.createElement('p');
            note.textContent = 'This table has grouped columns; its column layout is fixed.';
            this.panel.append(note);
        }
        this.select('Text', this.textSize, [['compact', 'Compact'], ['normal', 'Normal'], ['large', 'Large']], (v) => { this.textSize = v; });
        this.select('Table height', this.height, [['compact', 'Short'], ['normal', 'Normal'], ['large', 'Tall']], (v) => { this.height = v; });
        const reset = document.createElement('button');
        reset.type = 'button'; reset.className = 'btn-ghost'; reset.textContent = 'Reset layout';
        reset.addEventListener('click', async () => {
            this.block.inert = true;
            clearTimeout(this.timer);
            await this.pending;
            try {
                const response = await fetch(this.url, { method: 'DELETE', headers: this.headers(), credentials: 'same-origin' });
                if (!response.ok) throw new Error('Reset failed');
                this.order = this.columns.map((_, i) => i); this.hidden = []; this.widths = {};
                this.textSize = 'normal'; this.height = 'normal'; this.apply(); this.draw();
            } catch (_) { this.notice('Your table layout could not be reset'); }
            finally { this.block.inert = false; }
        });
        this.panel.append(reset);
    }

    select(labelText, value, choices, onChange) {
        const label = document.createElement('label'); label.className = 'tl-setting';
        label.append(document.createTextNode(labelText));
        const select = document.createElement('select');
        choices.forEach(([key, text]) => select.add(new Option(text, key, false, key === value)));
        select.addEventListener('change', () => { onChange(select.value); this.apply(); this.save(); });
        label.append(select); this.panel.append(label);
    }

    apply() {
        this.block.classList.remove('tl-text-compact', 'tl-text-large', 'tl-height-compact', 'tl-height-large');
        if (this.textSize !== 'normal') this.block.classList.add(`tl-text-${this.textSize}`);
        if (this.height !== 'normal') this.block.classList.add(`tl-height-${this.height}`);
        if (!this.canColumns) return;
        Array.from(this.table.rows).forEach((row) => {
            if (row.classList.contains('row-detail')) return;
            const cells = new Map(Array.from(row.cells).map((cell) => [Number(cell.dataset.tlColumn), cell]));
            cells.forEach((cell, index) => {
                const span = Number(cell.dataset.tlSpan || 1);
                const visible = Array.from({ length: span }, (_, offset) => index + offset)
                    .filter((i) => !this.hidden.includes(i)).length;
                cell.hidden = visible === 0;
                if (span > 1 && visible > 0) cell.colSpan = visible;
            });
            if (this.canReorder && cells.size === this.columns.length) {
                this.order.forEach((i) => { const cell = cells.get(i); if (cell) row.append(cell); });
            }
        });
        this.table.style.tableLayout = Object.keys(this.widths).length ? 'fixed' : '';
        this.columns.forEach((th, i) => { th.style.width = this.widths[i] ? `${this.widths[i]}px` : ''; });
        this.sizeDetailRows();
    }

    sizeDetailRows() {
        const visible = Math.max(1, this.columns.length - this.hidden.length);
        Array.from(this.table.tBodies).forEach((body) => Array.from(body.rows).forEach((row) => {
            if (row.classList.contains('row-detail') && row.cells[0]) row.cells[0].colSpan = visible;
        }));
    }

    headers() {
        return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' };
    }

    save() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            const data = { order: [...this.order], hidden: [...this.hidden], widths: { ...this.widths }, text_size: this.textSize, height: this.height };
            this.pending = this.pending.then(async () => {
                try {
                    const response = await fetch(this.url, { method: 'POST', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify(data) });
                    if (!response.ok) throw new Error('Save failed');
                } catch (_) { this.notice('Your table layout could not be saved'); }
            });
        }, 500);
    }

    notice(message) { window.Agora?.notify?.toast(message, 'error'); }
}
