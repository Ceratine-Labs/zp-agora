/**
 * The app bar's expandable panels.
 *
 * One panel per section, opening downward under the bar. Behaviour is
 * deliberately small: the nesting inside a panel is <details>, which the
 * browser already knows how to open, keyboard-operate and announce. This file
 * only handles the part the browser cannot infer — which panel is open, and
 * closing it.
 *
 * Hover does NOT open a panel. A menu that opens on hover fires while the
 * pointer is only crossing the bar, and on a touch screen it either opens on
 * the tap that was meant to navigate or never opens at all.
 */
export default function megaMenu() {
    const bar = document.getElementById('appbar');
    const scrim = document.getElementById('scrim');
    if (!bar) return;

    const buttons = Array.from(bar.querySelectorAll('[data-menu]'));
    const panels = new Map(
        Array.from(document.querySelectorAll('[data-panel]')).map((p) => [p.dataset.panel, p])
    );

    let open = null;

    const position = (panel) => {
        // The panel hangs from the bottom of the bar. Read the bar's real
        // height rather than trusting the token: it grows when the primary nav
        // wraps on a narrow window, and a hard-coded 52px would leave a gap.
        panel.style.top = `${bar.getBoundingClientRect().bottom + window.scrollY}px`;
    };

    const close = () => {
        if (!open) return;
        panels.get(open).hidden = true;
        bar.querySelector(`[data-menu="${open}"]`).setAttribute('aria-expanded', 'false');
        if (scrim) scrim.hidden = true;
        open = null;
    };

    const show = (code) => {
        if (open === code) return close();
        close();

        const panel = panels.get(code);
        if (!panel) return;

        position(panel);
        panel.hidden = false;
        bar.querySelector(`[data-menu="${code}"]`).setAttribute('aria-expanded', 'true');
        if (scrim) scrim.hidden = false;
        open = code;
    };

    buttons.forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            show(button.dataset.menu);
        });
    });

    if (scrim) scrim.addEventListener('click', close);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && open) {
            const button = bar.querySelector(`[data-menu="${open}"]`);
            close();
            button?.focus();
        }
    });

    // A click anywhere outside the bar and the open panel closes it. Checking
    // containment rather than a blur handler keeps a click INSIDE the panel —
    // on a disclosure twist, say — from closing the thing being expanded.
    document.addEventListener('click', (e) => {
        if (!open) return;
        if (bar.contains(e.target) || panels.get(open).contains(e.target)) return;
        close();
    });

    window.addEventListener('resize', () => {
        if (open) position(panels.get(open));
    });
}
