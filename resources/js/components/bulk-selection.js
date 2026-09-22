/**
 * Carrying a grid's ticked rows into a form that is not inside it.
 *
 * Opt in with `data-bulk-form="<grid key>"` on the form. It fills, and keeps
 * filled, two things:
 *
 *   <div data-bulk-items>    one <input type="hidden" name="items[]"> per
 *                            ticked row, valued with the row's key
 *   <span data-bulk-count>   how many, live
 *
 * WHY THE FORM CANNOT SIMPLY CONTAIN THE BOXES. The tick boxes live in the
 * grid's <table>, and the grid is not a form — it is a table inside a scope
 * form the header filters post into with `form=`. Putting a second form around
 * the table would nest one form inside another, which HTML does not allow and
 * the browser silently repairs by dropping it. The action the ticks feed is
 * also a modal: a <dialog> is moved to the top layer when it opens, so markup
 * that straddled the two would be torn apart at exactly the moment it is used.
 * Copying the values across at the last safe moment is the only arrangement
 * that survives both.
 *
 * KEPT IN STEP RATHER THAN READ ON SUBMIT. A submit handler would be enough
 * for the post itself, but the count is on screen while the person types the
 * reason, and a number that was right when the modal opened and is wrong now
 * is worse than no number. Delegated on the document, because data-grid.js
 * replaces rows and a listener bound to the boxes that were there at load goes
 * with them.
 *
 * `:not(:disabled)` matches check-all.js: table-tools.js disables the boxes in
 * rows a column filter has hidden so they leave the submission, and a batch
 * that included four hundred rows nobody could see is the failure the
 * disabling exists to prevent.
 */
export default function bulkSelection() {
    const forms = Array.from(document.querySelectorAll('[data-bulk-form]'));

    if (!forms.length) return;

    const sync = () => forms.forEach(fill);

    document.addEventListener('change', (event) => {
        if (event.target.matches?.('[data-check], [data-check-all]')) sync();
    });

    // The grid's own Clear button empties the boxes in script, which fires no
    // change event of its own — data-grid.js re-syncs its bar by hand and this
    // has to hear about it the same way.
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-selection-clear]')) setTimeout(sync, 0);
    });

    // A column filter changing what is visible changes what is ticked FOR THE
    // PURPOSES OF THE POST, because table-tools.js disables what it hides.
    document.addEventListener('table-tools:change', sync);

    sync();
}

function fill(form) {
    const grid = document.querySelector(`[data-grid-key="${form.dataset.bulkForm}"]`);
    const bin = form.querySelector('[data-bulk-items]');
    const count = form.querySelector('[data-bulk-count]');

    if (!grid || !bin) return;

    const keys = Array.from(grid.querySelectorAll('[data-check]:not(:disabled)'))
        .filter((box) => box.checked)
        .map((box) => box.value)
        .filter(Boolean);

    bin.replaceChildren(...keys.map((key) => {
        const field = document.createElement('input');

        field.type = 'hidden';
        field.name = 'items[]';
        field.value = key;

        return field;
    }));

    if (count) count.textContent = String(keys.length);

    // Nothing ticked is not a form worth submitting, and the server saying so
    // after the fact is a round trip that teaches nothing.
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = keys.length === 0;
    });
}
