/**
 * A select whose options depend on another select.
 *
 * Opt in with `data-linked-select="<name of the controlling select>"` on the
 * dependent one, and `data-when="<value>"` on each option that belongs to a
 * particular choice. An option with no `data-when` is always offered — that is
 * where "every area at this site" lives.
 *
 * WHY THIS EXISTS RATHER THAN A RELOAD. The stock recon centre asks for a site
 * and then a counting area, and the areas belong to the site: 250 of them
 * across the estate, twenty at a branch. Filling that list from the server
 * means the page has to come back every time the site changes, which throws
 * away the dates and the run name somebody has already typed — and Ryan's ask
 * for this screen was explicitly the least interaction that is still safe.
 *
 * NO JAVASCRIPT IS STILL CORRECT. Without this, every option is present and
 * selectable; the worst case is a person choosing an area that belongs to
 * another site, and the procedure refuses that by name
 * (AGORA:NO_SUCH_AREA). The filtering is a convenience, never the guard.
 *
 * `hidden` is not enough on its own — Safari has historically ignored it on an
 * <option> — so an option that does not apply is also disabled, which every
 * browser honours, and moved out of the way by wrapping it in a detached
 * fragment would lose the user's place. Disabled + hidden is the pair that
 * works everywhere.
 */
export default function linkedSelect() {
    document.querySelectorAll('[data-linked-select]').forEach((dependent) => {
        const form = dependent.closest('form');
        const controller = form?.querySelector(`[name="${dependent.dataset.linkedSelect}"]`);

        if (!controller) return;

        const apply = () => {
            const value = String(controller.value ?? '');
            let selectedIsGone = false;

            dependent.querySelectorAll('option').forEach((option) => {
                const when = option.dataset.when;
                const applies = when === undefined || when === value;

                option.hidden = !applies;
                option.disabled = !applies;

                if (!applies && option.selected) selectedIsGone = true;
            });

            // Falling back to the first option that still applies, rather than
            // leaving a selection the site no longer has. A select showing an
            // area from the previous branch is the screen lying about what it
            // will run.
            if (selectedIsGone) {
                const first = dependent.querySelector('option:not([disabled])');
                if (first) first.selected = true;
            }
        };

        controller.addEventListener('change', apply);
        apply();
    });
}
