/**
 * Searchable and multi-select dropdowns.
 *
 * TomSelect is loaded only when the page actually has one, which most do not.
 * Mark a select with `data-select` and it is upgraded on load; add
 * `data-select="multi"` for a multiple choice.
 *
 * A plain <select> is left alone on purpose. A branch picker with 25 entries
 * does not need a search box, and the native control is better on a phone than
 * anything a library draws.
 */
export default async function selects(root = document) {
    const fields = root.querySelectorAll('select[data-select]:not([data-select-ready])');
    if (fields.length === 0) return;

    const { default: TomSelect } = await import('tom-select');

    fields.forEach((field) => {
        field.setAttribute('data-select-ready', '');

        new TomSelect(field, {
            plugins: field.dataset.select === 'multi' ? ['remove_button'] : [],
            maxOptions: null,
            // Match anywhere in the option, not just at the start: people
            // search for "Ulundi", not "Caltex Ulundi".
            searchField: ['text'],
            placeholder: field.dataset.placeholder || 'Search…',
        });
    });
}
