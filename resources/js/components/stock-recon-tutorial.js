/**
 * Progressive enhancement for the stock recon guide. All teaching content is
 * rendered by Blade; this only selects existing chapters and example states.
 * It never fetches data, calculates proposals or submits a stock operation.
 */
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
        }

        deck.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
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
            followHash();
        });
        browser.addEventListener('hashchange', followHash);
        showChapter(deck.dataset.activeLesson);
        followHash();
    });
}
