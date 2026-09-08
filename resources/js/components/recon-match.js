/**
 * The manual reconcile workbench — the live half of <x-two-pane-recon>.
 *
 * Three behaviours, and each exists because the screen is unusable without it:
 *
 *   1. THE RUNNING TOTALS. "Do these two sides agree" is the only question
 *      this screen asks. Making somebody add up two columns of ticked rows to
 *      answer it would be absurd, so the strip above the panes reports the two
 *      sums and their difference as boxes are ticked.
 *
 *   2. THE REASON APPEARS WHEN IT IS NEEDED. A match whose sides do not
 *      balance is allowed — sometimes that is the truth of it — but only with
 *      a reason, and the procedure refuses without one. Showing the field only
 *      once the difference is non-zero is what makes that rule visible at the
 *      moment it applies instead of as a rejection afterwards.
 *
 *   3. CLICKING A COLOUR TAKES THE WHOLE GROUP. A coloured row means its
 *      reference appears on both sides; the pair is nearly always the whole
 *      group, on both sides at once. Ctrl/Cmd-click on a coloured row selects
 *      every row carrying that key, left and right.
 *
 * The formatting goes through window.Agora.format, which is the twin of
 * App\Support\Format — rebuilding the number format here is how the two drift.
 */
export default function reconMatch() {
    const pane = document.querySelector('[data-two-pane]');

    if (!pane) return;

    const form = pane.closest('form');
    const strip = document.querySelector('[data-match-totals]');
    const reason = document.querySelector('[data-match-reason]');
    const submit = document.querySelector('[data-match-submit]');

    const recount = () => report(pane, strip, reason, submit);

    pane.addEventListener('change', (event) => {
        if (event.target.matches('[data-pane-pick]')) recount();
    });

    // Select-all, per side. Each pane has its own: ticking every bank line is
    // a normal thing to want; ticking every row on the screen never is.
    pane.querySelectorAll('[data-pane-all]').forEach((box) => {
        box.addEventListener('change', () => {
            const side = box.dataset.paneAll;
            // Only the rows a column filter has left on screen: their boxes
            // are the ones still in the submission (table-tools.js disables
            // the rest), so ticking a disabled one would promise a row the
            // procedure is never sent.
            pane.querySelectorAll(`[data-pane-pick="${side}"]:not(:disabled)`).forEach((pick) => {
                pick.checked = box.checked;
            });
            recount();
        });
    });

    // Ctrl/Cmd-click a coloured row to take its whole group on both sides.
    pane.addEventListener('click', (event) => {
        if (!event.ctrlKey && !event.metaKey) return;

        const row = event.target.closest('[data-pair-key]');
        const key = row?.dataset.pairKey;

        if (!key) return;

        event.preventDefault();

        pane.querySelectorAll(`[data-pair-key="${CSS.escape(key)}"]`).forEach((match) => {
            const pick = match.querySelector('[data-pane-pick]:not(:disabled)');
            if (pick) pick.checked = true;
        });

        recount();
    });

    if (form) form.addEventListener('reset', () => window.setTimeout(recount, 0));

    // A filter takes rows out of the submission, so the two totals and the
    // difference are about different rows than they were a moment ago.
    pane.addEventListener('table-tools:change', recount);

    recount();
}

function report(pane, strip, reason, submit) {
    const bank = sum(pane, 'bank');
    const mops = sum(pane, 'mops');
    const difference = mops.total - bank.total;

    // Rounded to the cent before it is judged: two sums of money held as
    // floats can differ by 1e-10 and a screen that then demands a reason for a
    // match that balances is a screen nobody trusts.
    const off = Math.abs(Math.round(difference * 100)) > 0;

    if (strip) {
        const cells = strip.querySelectorAll('.stat');

        write(cells[0], money(bank.total), `${bank.count} ${bank.count === 1 ? 'line' : 'lines'}`);
        write(cells[1], money(mops.total), `${mops.count} ${mops.count === 1 ? 'row' : 'rows'}`);
        write(cells[2], money(difference), off ? 'a reason is required' : 'the two sides agree');
    }

    if (reason) {
        reason.hidden = !off;

        const field = reason.querySelector('input');

        // Only required while it is on screen — a hidden required field is a
        // form that cannot be submitted and does not say why.
        if (field) field.required = off;
    }

    if (submit) submit.disabled = bank.count === 0 || mops.count === 0;
}

function sum(pane, side) {
    const picked = pane.querySelectorAll(`[data-pane-pick="${side}"]:checked:not(:disabled)`);
    let total = 0;

    picked.forEach((pick) => {
        const row = pick.closest('[data-amount]');
        total += Number(row?.dataset.amount ?? 0);
    });

    return { count: picked.length, total };
}

/**
 * The rand format, through the twin of App\Support\Format.
 *
 * `R` is its name in that module — the same function the PHP side renders
 * every other figure on this screen with, so a total that appears while boxes
 * are ticked is formatted identically to one the server drew.
 */
function money(value) {
    return window.Agora?.format?.R ? window.Agora.format.R(value) : `R${value.toFixed(2)}`;
}

function write(cell, value, note) {
    if (!cell) return;

    const big = cell.querySelector('.v');
    const small = cell.querySelector('.n');

    if (big) big.textContent = value;
    if (small) small.textContent = note;
}
