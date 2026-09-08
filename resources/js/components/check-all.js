/**
 * A header checkbox that drives the boxes in its own table.
 *
 * Opt in with `data-check-all` on the header box and `data-check` on the rows.
 * It keeps itself in the indeterminate state when only some are ticked, which
 * is the only honest rendering of "partly selected" — an unticked box there
 * says "nothing is selected" and would be a lie.
 *
 * It stops the click from reaching the row, because a row in a grid with
 * row-detail expands when clicked and ticking a box is not asking for that.
 */
export default function checkAll() {
    document.querySelectorAll('[data-check-all]').forEach((master) => {
        const table = master.closest('table');
        if (!table) return;

        // `:not(:disabled)` is what makes select-all honest on a filtered
        // table. table-tools.js disables the boxes in rows a filter has hidden
        // so they leave the submission; a master box that still counted them
        // would tick four hundred invisible rows and report "all selected".
        const boxes = () => Array.from(table.querySelectorAll('[data-check]:not(:disabled)'));

        const sync = () => {
            const all = boxes();
            const ticked = all.filter((b) => b.checked).length;
            master.checked = ticked > 0 && ticked === all.length;
            master.indeterminate = ticked > 0 && ticked < all.length;
        };

        master.addEventListener('click', (event) => event.stopPropagation());
        master.addEventListener('change', () => {
            boxes().forEach((b) => { b.checked = master.checked; });
            table.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // A filter changing what is visible changes what "all" means.
        table.addEventListener('table-tools:change', sync);

        // Per box, because stopping the click has to happen BELOW the row —
        // a row with data-row-detail expands on click and ticking a box is not
        // asking for that, and a listener on the table is too late to prevent
        // it.
        boxes().forEach((box) => {
            box.addEventListener('click', (event) => event.stopPropagation());
        });

        // Delegated, because a row can be replaced after load — the group
        // runner swaps each site in as its preview answers — and a listener
        // bound to the box that was there at load goes with it.
        table.addEventListener('change', (event) => {
            if (event.target.matches?.('[data-check]')) sync();
        });

        sync();
    });
}
