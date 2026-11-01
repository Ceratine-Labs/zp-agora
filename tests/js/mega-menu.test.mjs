import test from 'node:test';
import assert from 'node:assert/strict';
import megaMenu, { menuSearchEntries } from '../../resources/js/components/mega-menu.js';

const entries = [
    { id: 'bank-overview', labels: ['Overview', 'Bank reconciliation', 'Cash'], active: true },
    { id: 'statement', labels: ['Statement lines', 'Bank reconciliation', 'Cash'], active: true },
    { id: 'card', labels: ['Card batches', 'Bank reconciliation', 'Cash'], active: false },
    { id: 'cafe', labels: ['Café reports Réconciliation journalière', 'Trading'], active: true },
    { id: 'stock', labels: ['Stock counts', 'Inventory'], active: true },
];
const ids = (query, activeOnly = true) => menuSearchEntries(entries, query, activeOnly).map(({ id }) => id);

test('menu search ignores case, accents and repeated whitespace', () => {
    assert.deepEqual(ids('  CAFE\t RECONCILIATION  '), ['cafe']);
    assert.deepEqual(ids('cafe\u0301 journaliere'), ['cafe']);
});

test('all query tokens can match an entry and its ancestor context in any order', () => {
    assert.deepEqual(ids('cash lines'), ['statement']);
    assert.deepEqual(ids('lines cash'), ['statement']);
    assert.deepEqual(ids('cash inventory'), []);
});

test('a heading match includes its descendants within the active filter', () => {
    assert.deepEqual(ids('bank'), ['bank-overview', 'statement']);
    assert.deepEqual(ids('bank', false), ['bank-overview', 'statement', 'card']);
    assert.deepEqual(ids('card'), []);
    assert.deepEqual(ids('card', false), ['card']);
});

test('clearing or whitespace resets search without resetting Show active', () => {
    assert.deepEqual(ids(''), ['bank-overview', 'statement', 'cafe', 'stock']);
    assert.deepEqual(ids(' \n ', false), ['bank-overview', 'statement', 'card', 'cafe', 'stock']);
});

test('unknown searches and empty permitted catalogues return no fabricated results', () => {
    assert.deepEqual(ids('not-on-this-menu'), []);
    assert.deepEqual(menuSearchEntries([], '', false), []);
    assert.deepEqual(menuSearchEntries(entries.filter((entry) => !entry.active), '', true), []);
});

test('query punctuation is literal text rather than executable regular expressions', () => {
    const literal = [{ id: 'literal', labels: ['Fees (A+B) [daily]'], active: true }];
    assert.deepEqual(menuSearchEntries(literal, '(a+b) [daily]'), literal);
    assert.deepEqual(menuSearchEntries(literal, '.*'), []);
    assert.deepEqual(menuSearchEntries(literal, '<script>'), []);
});

test('filtering preserves source order and does not mutate menu data', () => {
    const original = structuredClone(entries);
    const filtered = menuSearchEntries(entries, 'bank', false);
    assert.deepEqual(filtered.map(({ id }) => id), ['bank-overview', 'statement', 'card']);
    assert.equal(filtered[0], entries[0]);
    assert.deepEqual(entries, original);
});

function withMenu(callback, populate = () => ({})) {
    let focused = null;
    const element = (dataset = {}, tagName = 'DIV') => {
        const listeners = new Map();
        return {
            dataset, tagName, hidden: false, value: '', style: {}, attributes: {}, parentElement: null,
            addEventListener(type, listener) {
                if (!listeners.has(type)) listeners.set(type, []);
                listeners.get(type).push(listener);
            },
            fire(type, properties = {}) {
                const event = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...properties };
                listeners.get(type)?.forEach((listener) => listener(event));
                return event;
            },
            focus() { focused = this; },
            contains() { return false; },
            matches(selector) {
                return selector === '[data-menu-node]' ? Object.hasOwn(dataset, 'menuNode') : selector === 'details.mega-group' && tagName === 'DETAILS';
            },
            setAttribute(name, value) { this.attributes[name] = value; },
        };
    };
    const button = element({ menu: 'cash' });
    const input = element();
    const clear = element();
    const status = element();
    const empty = element();
    const activeOnly = element();
    activeOnly.checked = true;
    const panel = element({ panel: 'cash' });
    panel.hidden = true;
    const fixture = populate(element, panel);
    panel.querySelectorAll = (selector) => ({ '[data-menu-node]': fixture.nodes, '[data-menu-entry]': fixture.entries, 'details.mega-group': fixture.groups }[selector] ?? []);
    panel.querySelector = (selector) => ({ '[data-menu-search]': input, '[data-menu-search-clear]': clear, '[data-menu-search-status]': status, '[data-menu-search-empty]': empty }[selector] ?? null);
    const bar = element();
    bar.querySelectorAll = () => [button];
    bar.querySelector = () => button;
    bar.getBoundingClientRect = () => ({ bottom: 52 });
    const scrim = element();
    scrim.hidden = true;
    const document = element();
    document.getElementById = (id) => ({ appbar: bar, scrim }[id]);
    document.querySelectorAll = (selector) => selector === '[data-panel]' ? [panel] : selector === '[data-menu-active-only]' ? [activeOnly] : [];
    const window = element();
    window.scrollY = 0;
    const replacements = { document, window, localStorage: { getItem: () => null, setItem() {} } };
    const originals = Object.fromEntries(Object.keys(replacements).map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    try {
        Object.entries(replacements).forEach(([key, value]) => Object.defineProperty(globalThis, key, { configurable: true, value }));
        megaMenu();
        callback({ button, input, clear, panel, scrim, document, status, empty, activeOnly, fixture, focused: () => focused });
    } finally {
        Object.entries(originals).forEach(([key, descriptor]) => {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        });
    }
}

test('pointer opening leaves search unfocused while keyboard opening focuses it', () => {
    withMenu(({ button, input, panel, focused }) => {
        button.focus();
        button.fire('click', { detail: 1 });
        assert.equal(panel.hidden, false);
        assert.equal(focused(), button);
        button.fire('click', { detail: 1 });
        assert.equal(panel.hidden, true);
        button.fire('click', { detail: 0 });
        assert.equal(panel.hidden, false);
        assert.equal(focused(), input);
    });
});

test('ArrowDown opens search; Escape closes without clearing, and Clear refocuses search', () => {
    withMenu(({ button, input, clear, panel, scrim, document, focused }) => {
        const arrow = button.fire('keydown', { key: 'ArrowDown' });
        assert.equal(arrow.defaultPrevented, true);
        assert.equal(focused(), input);
        assert.equal(button.attributes['aria-expanded'], 'true');
        input.value = 'cash';
        input.fire('input');
        assert.equal(clear.hidden, false);
        const escape = document.fire('keydown', { key: 'Escape' });
        assert.equal(escape.defaultPrevented, true);
        assert.equal(panel.hidden, true);
        assert.equal(scrim.hidden, true);
        assert.equal(button.attributes['aria-expanded'], 'false');
        assert.equal(focused(), button);
        assert.equal(input.value, 'cash');
        button.fire('click', { detail: 0 });
        assert.equal(input.value, 'cash');
        clear.fire('click');
        assert.equal(input.value, '');
        assert.equal(clear.hidden, true);
        assert.equal(focused(), input);
    });
});

function groupedMenu(element, panel) {
    const child = (parent, dataset, tagName) => Object.assign(element(dataset, tagName), { parentElement: parent });
    const node = (parent, label, active = true, module = false) => child(parent, {
        menuNode: '', menuLabel: label, menuActive: String(active), ...(module ? { menuModuleGroup: '' } : {}),
    });
    const workflow = node(panel, 'Waiting on a decision');
    const cashups = node(workflow, 'Cashups', true, true);
    const purchasing = node(workflow, 'Purchasing', false, true);
    const closed = Object.assign(child(cashups, {}, 'DETAILS'), { open: false });
    const opened = Object.assign(child(cashups, {}, 'DETAILS'), { open: true });
    const shorts = child(closed, { menuEntry: '', menuLabel: 'Staff shorts', menuActive: 'true' });
    const banking = child(opened, { menuEntry: '', menuLabel: 'Daily banking', menuActive: 'true' });
    const request = child(purchasing, { menuEntry: '', menuLabel: 'Purchase requests', menuActive: 'false' });
    return {
        nodes: [workflow, cashups, purchasing], entries: [shorts, banking, request], groups: [closed, opened],
        workflow, cashups, purchasing, closed, opened, shorts, banking, request,
    };
}

test('module subheadings participate in search and preserve their workflow ancestors', () => {
    withMenu(({ input, status, fixture }) => {
        input.value = 'cashups';
        input.fire('input');
        assert.equal(fixture.cashups.hidden, false);
        assert.equal(fixture.workflow.hidden, false);
        assert.equal(fixture.purchasing.hidden, true);
        assert.equal(fixture.shorts.hidden, false);
        assert.equal(fixture.banking.hidden, false);
        assert.equal(status.textContent, '2 matching entries');

        input.value = 'decision cashups shorts';
        input.fire('input');
        assert.equal(fixture.shorts.hidden, false);
        assert.equal(fixture.banking.hidden, true);
        assert.equal(fixture.closed.open, true);
        assert.equal(fixture.opened.open, false);
        assert.equal(status.textContent, '1 matching entry');
    }, groupedMenu);
});

test('module search intersects Show active and Clear restores prior disclosures', () => {
    withMenu(({ input, clear, activeOnly, panel, empty, status, fixture }) => {
        input.value = 'purchasing';
        input.fire('input');
        assert.equal(fixture.workflow.hidden, true);
        assert.equal(fixture.purchasing.hidden, true);
        assert.equal(empty.hidden, false);
        assert.equal(status.textContent, '0 matching entries');

        activeOnly.checked = false;
        activeOnly.fire('change');
        assert.equal(panel.dataset.showActive, 'false');
        assert.equal(fixture.workflow.hidden, false);
        assert.equal(fixture.purchasing.hidden, false);
        assert.equal(fixture.request.hidden, false);
        assert.equal(fixture.cashups.hidden, true);
        assert.equal(empty.hidden, true);
        assert.equal(status.textContent, '1 matching entry');

        clear.fire('click');
        assert.equal(fixture.cashups.hidden, false);
        assert.equal(fixture.purchasing.hidden, false);
        assert.equal(fixture.closed.open, false);
        assert.equal(fixture.opened.open, true);
        assert.equal(status.textContent, '3 entries');
        activeOnly.checked = true;
        activeOnly.fire('change');
        assert.equal(panel.dataset.showActive, 'true');
        assert.equal(status.textContent, '2 active entries');
        assert.equal(fixture.purchasing.dataset.menuActive, 'false');
    }, groupedMenu);
});
