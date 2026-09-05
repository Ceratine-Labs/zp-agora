/**
 * A form that asks before it submits.
 *
 * Opt in with `data-confirm` on the form:
 *
 *   <form method="POST" data-confirm="Discard 12 previews?"
 *         data-confirm-text="…" data-confirm-action="Discard" data-confirm-danger>
 *
 * The dialog is SweetAlert2 through window.Agora.notify — nothing in Agora
 * calls window.confirm, which blocks the page, cannot be themed and reads as a
 * browser failure rather than the system asking a question.
 *
 * The submit is cancelled and re-issued rather than gated on a flag, because
 * the answer arrives asynchronously and there is no way to hold a submit event
 * open while a dialog is on screen. `form.submit()` is used deliberately on
 * the second pass: it does not fire another submit event, so this cannot loop.
 */
export default function confirmForm() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === 'yes') return;

            event.preventDefault();

            const ok = await window.Agora.notify.confirm(form.dataset.confirm, {
                text: form.dataset.confirmText || '',
                action: form.dataset.confirmAction || 'Continue',
                danger: form.hasAttribute('data-confirm-danger'),
            });

            if (!ok) return;

            form.dataset.confirmed = 'yes';
            form.submit();
        });
    });
}
