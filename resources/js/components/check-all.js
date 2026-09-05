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

        const boxes = () => Array.from(table.querySelectorAll('[data-check]'));

        const sync = () => {
            const all = boxes();
            const ticked = all.filter((b) => b.checked).length;
            master.checked = ticked > 0 && ticked === all.length;
            master.indeterminate = ticked > 0 && ticked < all.length;
        };

        master.addEventListener('click', (event) => event.stopPropagation());
        master.addEventListener('change', () => {
            boxes().forEach((b) => { b.checked = master.checked; });
        });

        boxes().forEach((box) => {
            box.addEventListener('click', (event) => event.stopPropagation());
            box.addEventListener('change', sync);
        });

        sync();
    });
}
