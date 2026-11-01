/**
 * The app bar's expandable panels.
 *
 * One panel per section, opening downward under the bar. Behaviour is
 * deliberately small: the nesting inside a panel is <details>, which the
 * browser already knows how to open, keyboard-operate and announce. This file
 * handles panel visibility and searches the permitted entries already on the
 * page. Search opens matching groups temporarily; clearing it restores them.
 *
 * Hover does NOT open a panel. A menu that opens on hover fires while the
 * pointer is only crossing the bar, and on a touch screen it either opens on
 * the tap that was meant to navigate or never opens at all.
 */
const searchText = (value) => String(value ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

export function menuSearchEntries(entries, query, activeOnly = true) {
    const tokens = searchText(query).trim().split(/\s+/u).filter(Boolean);
    return entries.filter((entry) => {
        if (activeOnly && !entry.active) return false;
        const text = searchText(entry.labels.join(' '));
        return tokens.every((token) => text.includes(token));
    });
}

function panelSearch(panel) {
    const input = panel.querySelector('[data-menu-search]');
    const clear = panel.querySelector('[data-menu-search-clear]');
    const status = panel.querySelector('[data-menu-search-status]');
    const empty = panel.querySelector('[data-menu-search-empty]');
    const nodes = Array.from(panel.querySelectorAll('[data-menu-node]'));
    const groups = Array.from(panel.querySelectorAll('details.mega-group'));
    const entries = Array.from(panel.querySelectorAll('[data-menu-entry]')).map((element) => {
        const ancestors = [];
        const disclosures = [];
        const labels = [element.dataset.menuLabel ?? element.textContent];
        let active = element.dataset.menuActive !== 'false';
        for (let parent = element.parentElement; parent && parent !== panel; parent = parent.parentElement) {
            if (parent.matches('[data-menu-node]')) {
                ancestors.push(parent);
                labels.push(parent.dataset.menuLabel ?? '');
                active &&= parent.dataset.menuActive !== 'false';
            }
            if (parent.matches('details.mega-group')) disclosures.push(parent);
        }
        return { element, ancestors, disclosures, labels, active };
    });
    let savedGroups = null;

    const apply = (activeOnly) => {
        const query = input?.value ?? '';
        const searching = query.trim() !== '';
        const matches = menuSearchEntries(entries, query, activeOnly);
        const visibleEntries = new Set(matches.map((entry) => entry.element));
        const visibleNodes = new Set(matches.flatMap((entry) => entry.ancestors));
        const visibleGroups = new Set(matches.flatMap((entry) => entry.disclosures));

        if (searching && savedGroups === null) savedGroups = new Map(groups.map((group) => [group, group.open]));
        entries.forEach(({ element }) => { element.hidden = searching && !visibleEntries.has(element); });
        nodes.forEach((node) => { node.hidden = searching && !visibleNodes.has(node); });
        if (searching) {
            groups.forEach((group) => { group.open = visibleGroups.has(group); });
        } else if (savedGroups !== null) {
            savedGroups.forEach((wasOpen, group) => { group.open = wasOpen; });
            savedGroups = null;
        }

        if (clear) clear.hidden = query.length === 0;
        if (status) {
            const noun = matches.length === 1 ? 'entry' : 'entries';
            status.textContent = `${matches.length} ${searching ? 'matching ' : activeOnly ? 'active ' : ''}${noun}`;
        }
        if (empty) {
            empty.hidden = matches.length !== 0;
            empty.textContent = searching
                ? activeOnly ? 'No matching active entries. Turn off Show active or change your search.' : 'No matching entries. Try another search.'
                : activeOnly ? 'No active entries in this menu. Turn off Show active to see all entries.' : 'No entries in this menu yet.';
        }
    };

    return { input, clear, apply };
}

export default function megaMenu() {
    const bar = document.getElementById('appbar');
    const scrim = document.getElementById('scrim');
    if (!bar) return;

    const buttons = Array.from(bar.querySelectorAll('[data-menu]'));
    const panels = new Map(
        Array.from(document.querySelectorAll('[data-panel]')).map((p) => [p.dataset.panel, p])
    );
    const searches = new Map(Array.from(panels, ([code, panel]) => [code, panelSearch(panel)]));

    // Both sets of entries are already on the page. Filtering only changes
    // visibility, retaining disclosure state and making no network request.
    const filters = Array.from(document.querySelectorAll('[data-menu-active-only]'));
    const preferenceKey = 'agora.menu.activeOnly';
    let activeOnly = true;
    try { activeOnly = localStorage.getItem(preferenceKey) !== 'false'; } catch { /* Storage may be disabled. */ }

    const filter = () => {
        panels.forEach((panel, code) => {
            panel.dataset.showActive = String(activeOnly);
            searches.get(code).apply(activeOnly);
        });
        filters.forEach((checkbox) => { checkbox.checked = activeOnly; });
    };
    filter();
    filters.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            activeOnly = checkbox.checked;
            filter();
            try { localStorage.setItem(preferenceKey, String(activeOnly)); } catch { /* Filtering still works. */ }
        });
    });
    searches.forEach(({ input, clear, apply }) => {
        input?.addEventListener('input', () => apply(activeOnly));
        input?.addEventListener('search', () => apply(activeOnly));
        clear?.addEventListener('click', () => {
            input.value = '';
            apply(activeOnly);
            input.focus();
        });
    });

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

    const show = (code, focusSearch = false) => {
        if (open === code) return close();
        close();

        const panel = panels.get(code);
        if (!panel) return;

        position(panel);
        panel.hidden = false;
        bar.querySelector(`[data-menu="${code}"]`).setAttribute('aria-expanded', 'true');
        if (scrim) scrim.hidden = false;
        open = code;
        if (focusSearch) searches.get(code).input?.focus({ preventScroll: true });
    };

    buttons.forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            show(button.dataset.menu, e.detail === 0);
        });
        button.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowDown') return;
            e.preventDefault();
            if (open !== button.dataset.menu) show(button.dataset.menu);
            searches.get(button.dataset.menu)?.input?.focus({ preventScroll: true });
        });
    });

    if (scrim) scrim.addEventListener('click', close);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && open) {
            e.preventDefault();
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
