/**
 * The run bar's one piece of behaviour: saying that it is running, once.
 *
 * These forms call a stored procedure over the customer's instance. Some of
 * them take seconds. Left alone, a person who does not see anything happen
 * presses Execute again, and the second press queues a second run of the same
 * procedure over the same 249 GB database. So on submit the bar disables its
 * own button, marks itself busy, and swaps the status line to the busy text.
 *
 * Three details that are easy to get wrong and were:
 *
 *   - **Disable on the next tick, not inside the handler.** A submit button
 *     disabled synchronously during `submit` is dropped from the payload, so
 *     a form that distinguishes its actions by the button's name and value
 *     arrives without one.
 *   - **Undo it on `pageshow`.** Come back with the browser's back button and
 *     the page is restored from the bfcache exactly as it was left — which is
 *     with Execute disabled forever. `event.persisted` is how that is caught.
 *   - **The status is a live region in the markup**, so the change is
 *     announced rather than only drawn.
 *
 * How long the run actually took is the server's to report: it puts the figure
 * in the `status` prop on the way back, because only it knows what the
 * procedure did. Timing it in the browser would measure the round trip and
 * call it the query.
 */
export default function runbar(root = document) {
    root.querySelectorAll('[data-runbar]:not([data-runbar-ready])').forEach((bar) => {
        bar.setAttribute('data-runbar-ready', '');

        const form = bar.closest('form');
        const go = bar.querySelector('[data-runbar-go]');
        const status = bar.querySelector('[data-runbar-status]');
        if (! form || ! go) return;

        const idle = status ? status.textContent : null;

        function release() {
            go.disabled = false;
            bar.removeAttribute('aria-busy');
            if (status && idle !== null) status.textContent = idle;
        }

        form.addEventListener('submit', () => {
            bar.setAttribute('aria-busy', 'true');
            if (status) status.textContent = bar.dataset.busy || 'Executing…';

            // Next tick: a button disabled inside the submit handler is left
            // out of the submitted data.
            window.setTimeout(() => { go.disabled = true; }, 0);
        });

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) release();
        });
    });
}
