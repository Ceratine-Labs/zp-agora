/**
 * A link that makes a POST — for a grid row action that changes something.
 *
 * <x-data-grid> renders its row actions as links, and most of them should be:
 * Open, Resume. A few change state — marking a reconciliation run complete —
 * and a change of state is never a GET. Opt in with `data-post="<url>"` on the
 * link. The optional confirmation reads the same attributes as
 * `<form data-confirm>` (confirm-form.js), so the two ask in the same words:
 *
 *   <a href="/app/recon/runs/412" data-post="/app/recon/runs/412/close"
 *      data-confirm="Mark run #412 complete?" data-confirm-text="…"
 *      data-confirm-action="Mark complete">Complete</a>
 *
 * The href stays real. With scripting off, the link opens the page where the
 * same action lives as an ordinary form; a middle-click or a modified click
 * still opens it in a new tab. With scripting on, a plain click builds a form
 * carrying the CSRF token and submits it: the same round trip the page's own
 * button makes, so the controller, its permission and its refusals are shared.
 *
 * Delegated from the document, because a grid can redraw its rows.
 */
export default function postLink() {
    document.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-post]');
        if (!link) return;

        // Anything but a plain left click is somebody opening the link.
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();

        // A second click while the first is still on its way is not a second request.
        if (link.dataset.posting === 'yes') return;

        if (link.dataset.confirm) {
            const ok = await window.Agora.notify.confirm(link.dataset.confirm, {
                text: link.dataset.confirmText || '',
                action: link.dataset.confirmAction || 'Continue',
                danger: link.hasAttribute('data-confirm-danger'),
            });

            if (!ok) return;
        }

        link.dataset.posting = 'yes';

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = link.dataset.post;
        form.hidden = true;

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
        form.appendChild(token);

        document.body.appendChild(form);
        form.submit();
    });
}
