import test from 'node:test';
import assert from 'node:assert/strict';
import stockReconTutorial, { findGuideMatches } from '../../resources/js/components/stock-recon-tutorial.js';

// A deliberately small DOM adapter: these tests exercise navigation state and
// independent comparisons, while the browser check owns layout and native keys.
class Element {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.attributes = new Map();
        this.listeners = new Map();
        this.selectors = new Map();
        this.hidden = false;
        this.textContent = '';
    }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    addEventListener(name, callback) { this.listeners.set(name, callback); }
    querySelectorAll(selector) { return this.selectors.get(selector) || []; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    focus() { this.focused = true; }
    scrollIntoView() { this.scrolled = true; }
    contains() { return true; }
    closest(selector) {
        if (selector === '[data-lesson-link]' && this.dataset.lessonLink) return this;
        if (selector === '[data-tutorial-event-link]' && this.dataset.tutorialEventLink) return this;
        if (selector === '[data-flow-case-link]' && this.dataset.flowCaseLink) return this;
        return null;
    }
    select(selector, ...elements) { this.selectors.set(selector, elements); return this; }
}

function example(stageNames = ['before', 'after']) {
    const scenario = new Element();
    const controls = new Element();
    controls.hidden = true;
    const buttons = stageNames.map((scenarioShow) => new Element({ scenarioShow }));
    const states = stageNames.map((scenarioState) => new Element({ scenarioState }));
    controls.select('[data-scenario-show]', ...buttons);
    scenario.select('[data-scenario-controls]', controls).select('[data-scenario-state]', ...states);
    return { scenario, controls, buttons, states };
}

function fixture(initial = 'purpose', hash = '', stageNames = ['before', 'after'], withFlows = false) {
    const browser = new Element();
    browser.location = new URL(`https://agora.example/app/stock-recon/tutorial?lesson=${initial}${hash}`);
    browser.history = {
        state: null,
        entries: [],
        pushState(state, title, url) {
            this.entries.push(String(url));
            browser.location = new URL(url);
        },
    };
    const deck = new Element({ activeLesson: initial });
    deck.ownerDocument = { defaultView: browser };
    const chapters = ['purpose', 'balancing', ...(withFlows ? ['visual-summary'] : [])].map((id) => {
        const heading = new Element();
        heading.textContent = id === 'purpose' ? 'Understand stock recon' : 'See balancing';
        return new Element({ tutorialLesson: id }).select('h2', heading);
    });
    const links = ['purpose', 'balancing'].map((lessonLink) => new Element({ lessonLink }));
    const event = new Element({ tutorialEvent: 'possible-duplicate' }).select('summary', new Element());
    const eventLink = new Element({ tutorialEventLink: 'possible-duplicate' });
    const progress = new Element();
    const position = new Element();
    const announcement = new Element();
    const examples = [example(stageNames), example()];
    const flowCases = withFlows ? ['opening-repair', 'possible-duplicate'].map((flowCase) => {
        const heading = new Element();
        heading.textContent = flowCase;
        return new Element({ flowCase }).select('[data-flow-title]', heading);
    }) : [];
    const flowLinks = flowCases.map((panel) => new Element({ flowCaseLink: panel.dataset.flowCase }));
    deck.select('[data-tutorial-lesson]', ...chapters)
        .select('[data-lesson-link]', ...links)
        .select('[data-tutorial-event]', event)
        .select('[data-tutorial-progress]', progress)
        .select('[data-tutorial-position]', position)
        .select('[data-tutorial-announcement]', announcement)
        .select('[data-flow-case]', ...flowCases)
        .select('[data-flow-case-link]', ...flowLinks)
        .select('[data-tutorial-scenario]', ...examples.map((entry) => entry.scenario));
    const root = { querySelectorAll: () => deck.getAttribute('data-tutorial-ready') === null ? [deck] : [] };
    stockReconTutorial(root);
    function click(target, overrides = {}) {
        const clickEvent = { target, button: 0, prevented: false, preventDefault() { this.prevented = true; }, ...overrides };
        deck.listeners.get('click')(clickEvent);
        return clickEvent;
    }
    return { root, browser, deck, chapters, links, event, eventLink, progress, position, announcement, examples, flowCases, flowLinks, click };
}

test('chapter links select content, update progress and preserve back navigation', () => {
    const guide = fixture();
    assert.deepEqual(guide.chapters.map((chapter) => chapter.hidden), [false, true]);
    assert.equal(guide.click(guide.links[1]).prevented, true);
    assert.deepEqual(guide.chapters.map((chapter) => chapter.hidden), [true, false]);
    assert.equal(guide.links[1].getAttribute('aria-current'), 'step');
    assert.equal(guide.links[0].getAttribute('aria-current'), null);
    assert.equal(guide.progress.value, 2);
    assert.equal(guide.position.textContent, '2 / 2');
    assert.equal(guide.announcement.textContent, 'Chapter 2 of 2: See balancing');
    assert.equal(guide.browser.location.searchParams.get('lesson'), 'balancing');
    assert.equal(guide.chapters[1].querySelector('h2').focused, true);

    guide.browser.location = new URL('https://agora.example/app/stock-recon/tutorial?lesson=purpose');
    guide.browser.listeners.get('popstate')();
    assert.deepEqual(guide.chapters.map((chapter) => chapter.hidden), [false, true]);
    assert.equal(guide.progress.value, 1);
    assert.equal(guide.browser.history.entries.length, 1);
});

test('modified clicks keep normal browser navigation, and unknown chapters are not intercepted', () => {
    const guide = fixture();
    assert.equal(guide.click(guide.links[1], { ctrlKey: true }).prevented, false);
    assert.equal(guide.click(new Element({ lessonLink: 'unknown' })).prevented, false);
    assert.equal(guide.deck.dataset.activeLesson, 'purpose');
    assert.equal(guide.browser.history.entries.length, 0);
});

test('example switches are independent and re-hydration preserves the current comparison', () => {
    const guide = fixture();
    const [first, second] = guide.examples;
    assert.equal(first.controls.hidden, false);
    assert.deepEqual(first.states.map((state) => state.hidden), [false, true]);
    first.buttons[1].listeners.get('click')();
    assert.deepEqual(first.states.map((state) => state.hidden), [true, false]);
    assert.equal(first.buttons[1].getAttribute('aria-pressed'), 'true');
    assert.equal(first.buttons[0].getAttribute('aria-pressed'), 'false');
    assert.deepEqual(second.states.map((state) => state.hidden), [false, true]);
    stockReconTutorial(guide.root);
    assert.deepEqual(first.states.map((state) => state.hidden), [true, false]);
});

test('event deep links open their disclosure and chapter navigation clears the old fragment', () => {
    const guide = fixture('balancing', '#tutorial-event-possible-duplicate');
    assert.equal(guide.event.open, true);
    assert.equal(guide.event.scrolled, true);
    guide.event.open = false;
    assert.equal(guide.click(guide.eventLink).prevented, false);
    assert.equal(guide.event.open, true);
    assert.equal(guide.event.querySelector('summary').focused, true);
    guide.click(guide.links[0]);
    assert.equal(guide.browser.location.hash, '');
});

test('a repaired basis or waste stage stays distinct from original and proposed values', () => {
    const guide = fixture('balancing', '', ['before', 'prepared', 'after']);
    const [first, second] = guide.examples;
    first.buttons[1].listeners.get('click')();
    assert.deepEqual(first.states.map((state) => state.hidden), [true, false, true]);
    assert.deepEqual(first.buttons.map((button) => button.getAttribute('aria-pressed')), ['false', 'true', 'false']);
    first.buttons[2].listeners.get('click')();
    assert.deepEqual(first.states.map((state) => state.hidden), [true, true, false]);
    assert.deepEqual(second.states.map((state) => state.hidden), [false, true]);
});

test('search finds all words across hidden content and ranks matching titles first', () => {
    const entries = [
        { id: 'body', title: 'A worked example', text: 'Opening repair: the issue multiple is 5. Waste is separate.' },
        { id: 'title', title: 'Issue multiple settings', text: 'Use the effective stock master value.' },
        { id: 'other', title: 'Opening counts', text: 'Repair the handover.' },
    ];
    assert.deepEqual(findGuideMatches(entries, ' ISSUE   multiple ').map((match) => match.id), ['title', 'body']);
    assert.deepEqual(findGuideMatches(entries, 'waste multiple').map((match) => match.id), ['body']);
    assert.match(findGuideMatches(entries, 'waste')[0].snippet, /Waste/);
    assert.deepEqual(findGuideMatches(entries, '   '), []);
    assert.deepEqual(findGuideMatches(entries, 'unknown'), []);
});

test('search treats event codes and markup-like input as literal text', () => {
    const entries = [{ id: 'd2', title: 'D2 · possible duplicate', text: 'Matching issues need independent evidence.' }];
    assert.equal(findGuideMatches(entries, 'd2')[0].id, 'd2');
    assert.deepEqual(findGuideMatches(entries, '<script>'), []);
    assert.deepEqual(findGuideMatches(entries, '[.*'), []);
});

test('diagram cases select one path, support deep links and restore history', () => {
    const guide = fixture('visual-summary', '#tutorial-flow-possible-duplicate', ['before', 'after'], true);
    assert.deepEqual(guide.flowCases.map((panel) => panel.hidden), [true, false]);
    assert.equal(guide.flowCases[1].querySelector('[data-flow-title]').focused, true);
    assert.equal(guide.click(guide.flowLinks[0]).prevented, true);
    assert.deepEqual(guide.flowCases.map((panel) => panel.hidden), [false, true]);
    assert.equal(guide.browser.location.searchParams.get('example'), 'opening-repair');
    assert.equal(guide.flowLinks[0].getAttribute('aria-current'), 'true');
    guide.browser.location = new URL('https://agora.example/app/stock-recon/tutorial?lesson=visual-summary&example=possible-duplicate');
    guide.browser.listeners.get('popstate')();
    assert.deepEqual(guide.flowCases.map((panel) => panel.hidden), [true, false]);
    guide.click(guide.links[0]);
    assert.equal(guide.browser.location.searchParams.has('example'), false);
    assert.equal(guide.click(guide.flowLinks[0], { ctrlKey: true }).prevented, false);
});
