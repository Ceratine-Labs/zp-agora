import test from 'node:test';
import assert from 'node:assert/strict';
import loader from '../../resources/js/components/loader.js';

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const flush = async () => { for (let i = 0; i < 5; i += 1) await Promise.resolve(); };

async function withPage(callback, { startup = true, hold = false, overlayPresent = true, hidden = false } = {}) {
    const element = (attributes = []) => {
        const attrs = new Set(attributes);
        const listeners = new Map();
        return {
            dataset: {}, hidden: false,
            hasAttribute: (name) => attrs.has(name),
            removeAttribute: (name) => attrs.delete(name),
            setAttribute: (name) => attrs.add(name),
            addEventListener(type, listener) {
                if (!listeners.has(type)) listeners.set(type, []);
                listeners.get(type).push(listener);
            },
            dispatchEvent(event) { listeners.get(event.type)?.forEach((listener) => listener(event)); },
        };
    };
    const root = element(startup ? ['data-app-starting'] : []);
    const label = { textContent: 'Opening…' };
    const overlay = element([...(startup ? ['data-loader-startup'] : []), ...(hold ? ['data-loader-hold'] : [])]);
    overlay.hidden = !startup && !hold;
    overlay.querySelector = (selector) => selector === '[data-loader-label]' ? label : null;
    const table = element();
    const document = Object.assign(element(), {
        documentElement: root, hidden,
        querySelector: () => overlayPresent ? overlay : null,
        querySelectorAll: () => hold ? [table] : [],
    });
    let now = 0;
    let nextId = 0;
    let finishes = 0;
    let frames = [];
    const timers = new Map();
    const window = Object.assign(element(), {
        Agora: { startup: { finish() { finishes += 1; root.removeAttribute('data-app-starting'); } } },
        setTimeout(callback, delay) { const id = ++nextId; timers.set(id, { at: now + delay, callback }); return id; },
        clearTimeout(id) { timers.delete(id); },
        requestAnimationFrame(callback) { frames.push(callback); },
    });
    const events = [];
    document.addEventListener('agora:ready', (event) => events.push(event.detail));
    const errors = [];
    const replacements = {
        document, window,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        console: { ...console, error: (...args) => errors.push(args) },
    };
    const originals = Object.fromEntries(Object.keys(replacements).map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    try {
        Object.entries(replacements).forEach(([key, value]) => Object.defineProperty(globalThis, key, { configurable: true, value }));
        const api = loader();
        await callback({
            api, overlay, root, table, document, window, events, errors, label,
            finishes: () => finishes,
            async frame() { const pending = frames; frames = []; pending.forEach((callback) => callback()); await flush(); },
            async advance(milliseconds) {
                now += milliseconds;
                for (const [id, timer] of [...timers]) {
                    if (timer.at <= now) { timers.delete(id); timer.callback(); }
                }
                await flush();
            },
        });
    } finally {
        Object.entries(originals).forEach(([key, descriptor]) => {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        });
    }
}

test('startup waits for setup and two paint frames even when held tables finish first', async () => {
    await withPage(async ({ api, overlay, root, table, document, frame, events, finishes }) => {
        const setup = deferred();
        const outcome = { failures: [] };
        const ready = api.ready(setup.promise);
        table.setAttribute('data-tt-ready');
        document.dispatchEvent({ type: 'table-tools:ready' });
        assert.equal(overlay.hasAttribute('data-loader-hold'), false);
        assert.equal(overlay.hidden, false);
        setup.resolve(outcome);
        await flush();
        await frame();
        assert.equal(overlay.hidden, false, 'The final layout has not crossed its paint yet.');
        await frame();
        assert.deepEqual(await ready, { reason: 'ready', outcome });
        assert.equal(overlay.hidden, true);
        assert.equal(overlay.hasAttribute('data-loader-startup'), false);
        assert.equal(root.hasAttribute('data-app-starting'), false);
        assert.equal(root.dataset.appReady, 'ready');
        assert.equal(events.length, 1);
        assert.equal(finishes(), 1);
    }, { hold: true });
});

test('action holds and startup release cannot cancel each other', async () => {
    await withPage(async ({ api, overlay, frame }) => {
        const early = api.show('Reading…');
        early.hide();
        assert.equal(overlay.hidden, false, 'An action ending must not reveal unfinished startup.');
        const pending = api.show('Saving…');
        const ready = api.ready({ failures: [] });
        await flush();
        await frame();
        await frame();
        await ready;
        assert.equal(overlay.hidden, false, 'Startup ending must not hide a pending action.');
        pending.hide();
        assert.equal(overlay.hidden, true);
        pending.hide();
        assert.equal(overlay.hidden, true);
    });
});

test('ordinary action waits keep their delay and independent holder counts', async () => {
    await withPage(async ({ api, overlay, label, advance }) => {
        const quick = api.show('Quick');
        await advance(299);
        assert.equal(overlay.hidden, true);
        quick.hide();
        await advance(1);
        assert.equal(overlay.hidden, true);
        const first = api.show('First');
        const second = api.show('Second');
        await advance(300);
        assert.equal(overlay.hidden, false);
        first.hide();
        assert.equal(overlay.hidden, false);
        first.hide();
        assert.equal(overlay.hidden, false, 'Repeated release must not consume another holder.');
        second.update('Finishing');
        assert.equal(label.textContent, 'Finishing');
        second.hide();
        assert.equal(overlay.hidden, true);
    }, { startup: false });
});

test('a rejected initializer reveals usable HTML and reports partial readiness', async () => {
    await withPage(async ({ api, overlay, root, events, errors }) => {
        const ready = api.ready(Promise.reject(new Error('Optional chunk unavailable')));
        assert.equal((await ready).reason, 'partial');
        assert.equal(overlay.hidden, true);
        assert.equal(root.hasAttribute('data-app-starting'), false);
        assert.equal(overlay.hasAttribute('data-loader-hold'), false);
        assert.equal(events.length, 1);
        assert.equal(errors.length, 1);
    }, { hold: true });
});

test('a stuck initializer times out once and cannot later hide an action', async () => {
    await withPage(async ({ api, overlay, root, advance, frame, events }) => {
        const setup = deferred();
        const ready = api.ready(setup.promise);
        await advance(59999);
        assert.equal(overlay.hidden, false);
        await advance(1);
        assert.equal((await ready).reason, 'timeout');
        assert.equal(overlay.hidden, true);
        assert.equal(root.hasAttribute('data-app-starting'), false);
        const action = api.show('Saving after recovery…');
        await advance(300);
        setup.resolve({ failures: [] });
        await flush();
        await frame();
        await frame();
        assert.equal(overlay.hidden, false);
        assert.equal(events.length, 1);
        action.hide();
        assert.equal(overlay.hidden, true);
    });
});

test('back-forward restore clears stale navigation and startup holds', async () => {
    await withPage(async ({ api, overlay, window, events, advance }) => {
        const ready = api.ready(new Promise(() => {}));
        api.show('Navigating…');
        window.dispatchEvent({ type: 'pageshow', persisted: true });
        assert.equal((await ready).reason, 'bfcache');
        assert.equal(overlay.hidden, true);
        await advance(60000);
        assert.equal(events.length, 1);
        assert.equal(overlay.hidden, true);
    }, { hold: true });
});

test('hidden documents and shells without an overlay can finish without animation frames', async () => {
    await withPage(async ({ api, root }) => {
        const action = api.show();
        action.update('Still safe');
        action.hide();
        assert.equal((await api.ready({ failures: [] })).reason, 'ready');
        assert.equal(root.hasAttribute('data-app-starting'), false);
    }, { hidden: true, overlayPresent: false });
});
