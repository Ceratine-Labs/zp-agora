/** Apply recon header filters when changed, so a selected status describes the rows. */
export default function reconRunFilters(root = document) {
    root.querySelectorAll('[data-grid-key="app.recon.runs"]:not([data-recon-filters-ready])').forEach((grid) => {
        grid.dataset.reconFiltersReady = '1';
        grid.addEventListener('change', (event) => {
            const field = event.target;
            if (!field.closest('.dg-filters') || !field.form) return;
            if (field.name.includes('[Status]') && [...(field.selectedOptions ?? [])]
                .some(option => !['previewing', 'previewed'].includes(option.value))) {
                let show = field.form.querySelector('[name="show"]');
                if (!show) {
                    show = document.createElement('input');
                    show.type = 'hidden'; show.name = 'show'; field.form.append(show);
                }
                show.value = 'all';
            }
            field.form.requestSubmit();
        });
    });
}
