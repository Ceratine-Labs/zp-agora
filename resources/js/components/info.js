/**
 * <x-info>: the "i" button that holds a screen's standing explanation.
 *
 * The <details> already opens and closes on its own. This adds what a popover
 * is expected to do and a <details> does not: one open at a time, Escape or a
 * click anywhere else closes it, and the panel is nudged left when it would
 * run off the right edge (a button beside a field in the last column, or on
 * a phone).
 */
export default function info(root = document) {
    root.querySelectorAll('details.info:not([data-info-ready])').forEach((el) => {
        el.setAttribute('data-info-ready', '');

        el.addEventListener('toggle', () => {
            const body = el.querySelector(':scope > .info-body');

            if (!el.open || !body) return;

            document.querySelectorAll('details.info[open]').forEach((other) => {
                if (other !== el) other.open = false;
            });

            body.style.left = '';
            const gutter = 16;
            const rect = body.getBoundingClientRect();
            const over = rect.right - (document.documentElement.clientWidth - gutter);

            if (over > 0) {
                body.style.left = `${Math.min(0, -over)}px`;
            }
        });
    });

    if (document.documentElement.dataset.infoWired) return;
    document.documentElement.dataset.infoWired = '1';

    document.addEventListener('click', (event) => {
        document.querySelectorAll('details.info[open]').forEach((el) => {
            if (!el.contains(event.target)) el.open = false;
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        document.querySelectorAll('details.info[open]').forEach((el) => {
            el.open = false;
            el.querySelector(':scope > summary')?.focus();
        });
    });
}
