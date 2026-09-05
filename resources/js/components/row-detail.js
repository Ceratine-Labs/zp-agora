/**
 * Expanding a table row to the detail behind it.
 *
 * Feature-rules §3.5 asks for a detail panel on row click. This is the
 * expanding-row form of it, which is what a proposal wants: the question a
 * recon clerk asks of an aggregate is "which lines", and the answer belongs
 * directly under the total it explains rather than in a panel to the side
 * where the eye has to carry the number across.
 *
 * The markup contract, so any grid can opt in:
 *
 *   <table data-row-detail>
 *     <tr data-detail-url="/…"> … </tr>
 *
 * Everything else is derived. The fragment is fetched once and kept, so
 * collapsing and expanding again costs nothing, and it is fetched lazily —
 * a month of ABSA is a few hundred proposals and drilling all of them up
 * front would be a few hundred queries nobody asked for.
 *
 * The server returns HTML, not JSON. The number formats live in
 * App\Support\Format and rebuilding them here is how the two drift apart.
 */
export default function rowDetail() {
    document.querySelectorAll('table[data-row-detail]').forEach((table) => {
        table.querySelectorAll('tr[data-detail-url]').forEach((row) => prepare(table, row));
    });
}

function prepare(table, row) {
    const columns = row.cells.length;

    // A row that can open says so before it is clicked. Without this the only
    // way to discover the behaviour is to click something and see.
    row.classList.add('is-expandable');
    row.tabIndex = 0;
    row.setAttribute('role', 'button');
    row.setAttribute('aria-expanded', 'false');

    const toggle = () => open(table, row, columns);

    row.addEventListener('click', (event) => {
        // A row can carry links — a reference that navigates (§3.7) — and
        // following one must not also expand the row underneath it.
        if (event.target.closest('a, button, input, select, label')) return;
        toggle();
    });

    row.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        toggle();
    });
}

function open(table, row, columns) {
    const existing = row.nextElementSibling;

    if (existing && existing.classList.contains('row-detail')) {
        const shown = !existing.hidden;
        existing.hidden = shown;
        row.classList.toggle('is-open', !shown);
        row.setAttribute('aria-expanded', String(!shown));
        return;
    }

    const holder = document.createElement('tr');
    holder.className = 'row-detail';

    const cell = document.createElement('td');
    cell.colSpan = columns;
    cell.innerHTML = '<p class="row-detail-loading">Reading the rows behind this…</p>';
    holder.append(cell);
    row.after(holder);

    row.classList.add('is-open');
    row.setAttribute('aria-expanded', 'true');

    fetch(row.dataset.detailUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => {
            if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
            return response.text();
        })
        .then((html) => { cell.innerHTML = html; })
        .catch((error) => {
            // Said in the row rather than in a toast: the failure belongs to
            // this line, and a toast would leave the row looking merely empty.
            cell.innerHTML =
                '<p class="row-detail-error">The detail could not be read — '
                + escapeHtml(error.message)
                + '. The figures in the row above still stand; they came from the preview.</p>';
        });
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}
