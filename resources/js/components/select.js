/**
 * Searchable and multi-select dropdowns.
 *
 * TomSelect is loaded only when the page actually has one, which most do not.
 * Mark a select with `data-select` and it is upgraded on load; a `multiple`
 * select gets the remove-button plugin so each choice is a chip you can drop.
 *
 * A plain <select> is left alone on purpose. A branch picker with 25 entries
 * does not need a search box, and the native control is better on a phone than
 * anything a library draws.
 *
 * THE STYLESHEET IS NOT OPTIONAL, and leaving it out does not degrade — it
 * breaks. TomSelect hides the original control with a CLASS (.ts-hidden-
 * accessible) rather than an inline style, so with no stylesheet the native
 * <select multiple> stays on the page at full size and the widget renders
 * underneath it as an unstyled text box. That is exactly what the sites picker
 * looked like: an OS-blue listbox with an orphaned "Search…" field below it.
 *
 * The bootstrap5 skin is the one to load, because it is written against
 * --bs-* custom properties and _bootstrap-bridge.scss already repoints those
 * at Agora's tokens. The control then follows the light/dark switch on its own.
 * _select.scss covers the few pieces the bridge does not reach.
 */
export default async function selects(root = document) {
    const fields = root.querySelectorAll('select[data-select]:not([data-select-ready])');
    if (fields.length === 0) return;

    const [{ default: TomSelect }] = await Promise.all([
        import('tom-select'),
        import('tom-select/dist/css/tom-select.bootstrap5.css'),
    ]);

    fields.forEach((field) => {
        field.setAttribute('data-select-ready', '');

        // `multiple` is the property that decides this, not the data attribute.
        // A multi-select declared only in HTML was still being built as a
        // single-choice control, so choices replaced each other silently.
        const multiple = field.multiple || field.dataset.select === 'multi';

        new TomSelect(field, {
            plugins: multiple ? ['remove_button'] : [],
            maxOptions: null,
            // Match anywhere in the option, not just at the start: people
            // search for "Ulundi", not "Caltex Ulundi".
            searchField: ['text'],
            placeholder: field.dataset.placeholder || 'Search…',
            // A multi-select is a filter: the list should stay open while
            // several are picked rather than closing after each one.
            closeAfterSelect: !multiple,
            hidePlaceholder: false,
            // The grid's set filter lives inside `.table-scroll`, which is a
            // scroll container on both axes and clips its own children — so a
            // dropdown drawn in place was cut off at the table's edge. In the
            // body it is clipped by nothing.
            dropdownParent: 'body',
        });
    });
}
