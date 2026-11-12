/**
 * Progressive enhancement for the stock recon guide. All teaching content is
 * rendered by Blade; this selects chapters, searches their text and follows
 * fixed example diagrams.
 * It never fetches data, calculates proposals or submits a stock operation.
 */
const guideText = (value) => String(value).normalize('NFKC').replace(/\s+/gu, ' ').trim();

/** Literal, case-insensitive AND search over the complete rendered guide. */
export function findGuideMatches(entries, query) {
    const terms = guideText(query).toLocaleLowerCase().split(' ').filter(Boolean);
    if (!terms.length) return [];
    return entries.map((entry) => {
        const text = guideText(entry.text);
        const lower = text.toLocaleLowerCase();
        const title = guideText(entry.title).toLocaleLowerCase();
        if (!terms.every((term) => `${title} ${lower}`.includes(term))) return null;
        const hits = terms.map((term) => lower.indexOf(term)).filter((index) => index >= 0);
        const start = Math.max(0, (hits.length ? Math.min(...hits) : 0) - 55);
        const snippet = `${start ? '…' : ''}${text.slice(start, start + 180)}${text.length > start + 180 ? '…' : ''}`;
        return { ...entry, snippet, score: terms.filter((term) => title.includes(term)).length };
    }).filter(Boolean).sort((a, b) => b.score - a.score);
}

export default function stockReconTutorial(root = document) {
    root.querySelectorAll('[data-stock-recon-tutorial]:not([data-tutorial-ready])').forEach((deck) => {
        deck.setAttribute('data-tutorial-ready', '');
        const browser = deck.ownerDocument.defaultView;
        const chapters = Array.from(deck.querySelectorAll('[data-tutorial-lesson]'));
        const links = Array.from(deck.querySelectorAll('[data-lesson-link]'));
        const events = Array.from(deck.querySelectorAll('[data-tutorial-event]'));
        const progress = deck.querySelector('[data-tutorial-progress]');
        const position = deck.querySelector('[data-tutorial-position]');
        const announcement = deck.querySelector('[data-tutorial-announcement]');
        const flowCases = Array.from(deck.querySelectorAll('[data-flow-case]'));
        const flowLinks = Array.from(deck.querySelectorAll('[data-flow-case-link]'));

        function showFlowCase(id, { focus = false, remember = false } = {}) {
            const active = flowCases.find((panel) => panel.dataset.flowCase === id);
            if (!active) return false;
            flowCases.forEach((panel) => { panel.hidden = panel !== active; });
            flowLinks.forEach((link) => {
                if (link.dataset.flowCaseLink === id) link.setAttribute('aria-current', 'true');
                else link.removeAttribute('aria-current');
            });
            const heading = active.querySelector('[data-flow-title]');
            if (remember) {
                const url = new URL(browser.location.href);
                url.searchParams.set('lesson', 'visual-summary');
                url.searchParams.set('example', id);
                url.hash = '';
                browser.history.pushState(browser.history.state, '', url);
            }
            if (focus && heading) {
                heading.focus({ preventScroll: true });
                heading.scrollIntoView({ block: 'start' });
                if (announcement) announcement.textContent = `Diagram: ${heading.textContent.trim()}`;
            }
            return true;
        }

        function showChapter(id, { focus = false, remember = false } = {}) {
            const index = chapters.findIndex((chapter) => chapter.dataset.tutorialLesson === id);
            if (index < 0) return false;
            const active = chapters[index];
            chapters.forEach((chapter) => { chapter.hidden = chapter !== active; });
            links.forEach((link) => {
                if (link.dataset.lessonLink === id) link.setAttribute('aria-current', 'step');
                else link.removeAttribute('aria-current');
            });
            deck.dataset.activeLesson = id;
            if (progress) progress.value = index + 1;
            if (position) position.textContent = `${index + 1} / ${chapters.length}`;
            const heading = active.querySelector('h2');
            if (announcement) announcement.textContent = `Chapter ${index + 1} of ${chapters.length}: ${heading?.textContent.trim() || id}`;
            if (remember) {
                const url = new URL(browser.location.href);
                url.searchParams.set('lesson', id);
                if (id !== 'visual-summary') url.searchParams.delete('example');
                url.hash = '';
                browser.history.pushState(browser.history.state, '', url);
            }
            if (focus && heading) {
                heading.focus({ preventScroll: true });
                heading.scrollIntoView({ block: 'start' });
            }
            return true;
        }

        function showEvent(id, focus = false) {
            const detail = events.find((event) => event.dataset.tutorialEvent === id);
            if (! detail) return false;
            detail.open = true;
            if (focus) detail.querySelector('summary')?.focus({ preventScroll: true });
            detail.scrollIntoView({ block: 'start' });
            return true;
        }

        function followHash() {
            const prefix = '#tutorial-event-';
            const hash = browser.location.hash;
            if (hash.startsWith(prefix)) showEvent(hash.slice(prefix.length));
            const flowPrefix = '#tutorial-flow-';
            if (hash.startsWith(flowPrefix)) {
                const id = hash.slice(flowPrefix.length);
                if (flowCases.some((panel) => panel.dataset.flowCase === id)) {
                    showChapter('visual-summary');
                    showFlowCase(id, { focus: true });
                }
            }
        }

        deck.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const flowLink = event.target.closest('[data-flow-case-link]');
            if (flowLink && deck.contains(flowLink)) {
                if (flowCases.some((panel) => panel.dataset.flowCase === flowLink.dataset.flowCaseLink)) {
                    showChapter('visual-summary');
                    showFlowCase(flowLink.dataset.flowCaseLink, { focus: true, remember: true });
                    event.preventDefault();
                }
                return;
            }
            const chapterLink = event.target.closest('[data-lesson-link]');
            if (chapterLink && deck.contains(chapterLink)) {
                if (showChapter(chapterLink.dataset.lessonLink, { focus: true, remember: true })) event.preventDefault();
                return;
            }
            const eventLink = event.target.closest('[data-tutorial-event-link]');
            if (eventLink && deck.contains(eventLink)) {
                // Leave the real fragment link to the browser, including its
                // history entry. Opening it first makes its target measurable.
                showEvent(eventLink.dataset.tutorialEventLink, true);
            }
        });

        const search = deck.querySelector('[data-guide-search]');
        if (search) {
            const input = search.querySelector('[data-guide-query]');
            const results = search.querySelector('[data-guide-search-results]');
            const status = search.querySelector('[data-guide-search-status]');
            const clear = search.querySelector('[data-guide-clear]');
            const document = deck.ownerDocument;
            const searchText = (element) => {
                const walker = document.createTreeWalker(element, browser.NodeFilter.SHOW_TEXT);
                const parts = [];
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (!node.parentElement?.closest('svg, [aria-hidden="true"]')) parts.push(node.textContent);
                }
                return guideText(parts.join(' '));
            };
            // textContent includes inactive chapters, closed event disclosures
            // and every example stage. Exclude repeated navigation and index
            // diagram cases individually so results open the intended case.
            const entries = chapters.map((chapter, index) => {
                const copy = chapter.cloneNode(true);
                copy.querySelectorAll('.sr-chapter-footer, .sr-visual-cases').forEach((node) => node.remove());
                return { type: 'chapter', id: chapter.dataset.tutorialLesson, title: chapter.querySelector('h2').textContent, text: searchText(copy), label: `Chapter ${index + 1}` };
            }).concat(events.map((detail) => ({
                type: 'event', id: detail.dataset.tutorialEvent, title: `${detail.querySelector('summary strong').textContent} · ${detail.querySelector('summary small').textContent}`, text: searchText(detail), label: 'Event guide',
            })), flowCases.map((panel) => ({
                type: 'diagram', id: panel.dataset.flowCase, title: panel.querySelector('[data-flow-title]').textContent, text: searchText(panel), label: 'Visual summary · case',
            })));

            function runSearch() {
                const query = input.value.trim();
                const matches = findGuideMatches(entries, query);
                results.replaceChildren();
                results.hidden = !query || matches.length === 0;
                clear.hidden = !query;
                if (!query) {
                    status.textContent = 'Search every chapter, worked example, diagram and event code.';
                    return;
                }
                status.textContent = matches.length
                    ? `${matches.length} ${matches.length === 1 ? 'result' : 'results'}${matches.length > 12 ? ' · showing the first 12; add a word to narrow your search' : ''}.`
                    : `No results for “${query}”. Try a shorter phrase, an item setting or an event code.`;
                matches.slice(0, 12).forEach((match) => {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    const url = new URL(browser.location.href);
                    url.hash = '';
                    url.searchParams.delete('example');
                    if (match.type === 'event') {
                        url.hash = `tutorial-event-${match.id}`;
                        link.dataset.tutorialEventLink = match.id;
                    } else if (match.type === 'diagram') {
                        url.searchParams.set('lesson', 'visual-summary');
                        url.searchParams.set('example', match.id);
                        url.hash = `tutorial-flow-${match.id}`;
                        link.dataset.flowCaseLink = match.id;
                    } else {
                        url.searchParams.set('lesson', match.id);
                        link.dataset.lessonLink = match.id;
                    }
                    link.href = String(url);
                    const label = document.createElement('small');
                    label.textContent = match.label;
                    const title = document.createElement('strong');
                    title.textContent = guideText(match.title);
                    const snippet = document.createElement('span');
                    snippet.textContent = match.snippet;
                    link.append(label, title, snippet);
                    item.append(link);
                    results.append(item);
                });
            }
            input.addEventListener('input', runSearch);
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    input.value = '';
                    runSearch();
                } else if (event.key === 'Enter' || event.key === 'ArrowDown') {
                    const first = results.querySelector('a');
                    if (first) { event.preventDefault(); first.focus(); }
                }
            });
            clear.addEventListener('click', () => { input.value = ''; runSearch(); input.focus(); });
            search.hidden = false;
            deck.querySelectorAll('[data-guide-search-link]').forEach((link) => { link.hidden = false; });
        }

        deck.querySelectorAll('[data-tutorial-scenario]').forEach((scenario) => {
            const controls = scenario.querySelector('[data-scenario-controls]');
            if (! controls) return;
            const buttons = Array.from(controls.querySelectorAll('[data-scenario-show]'));
            const states = Array.from(scenario.querySelectorAll('[data-scenario-state]'));
            function showState(id) {
                if (! states.some((state) => state.dataset.scenarioState === id)) return;
                states.forEach((state) => { state.hidden = state.dataset.scenarioState !== id; });
                buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.scenarioShow === id)));
            }
            controls.hidden = false;
            buttons.forEach((button) => button.addEventListener('click', () => showState(button.dataset.scenarioShow)));
            showState('before');
        });

        browser.addEventListener('popstate', () => {
            const id = new URL(browser.location.href).searchParams.get('lesson');
            showChapter(id || chapters[0]?.dataset.tutorialLesson);
            const example = new URL(browser.location.href).searchParams.get('example');
            if (!showFlowCase(example)) showFlowCase(flowCases[0]?.dataset.flowCase);
            followHash();
        });
        browser.addEventListener('hashchange', followHash);
        showChapter(deck.dataset.activeLesson);
        const example = new URL(browser.location.href).searchParams.get('example');
        if (!showFlowCase(example)) showFlowCase(flowCases[0]?.dataset.flowCase);
        followHash();
    });
}
