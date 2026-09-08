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
 *
 * `data-confirm-filtered="<table id>"` — one id, or several separated by
 * commas — makes the dialog name an active column filter before the rest of
 * its text:
 *
 *   <form data-confirm="Reconcile the selected batches in PumpIT?"
 *         data-confirm-filtered="recon-lines-244" …>
 *
 * WHY IT IS HERE RATHER THAN LEFT TO THE COUNT ON THE BUTTON. A filter on a
 * screen that commits takes rows out of the submission — that is deliberate,
 * and it is what stops a clerk stamping rows they cannot see. But the reverse
 * mistake is just as real and nothing guarded it: narrow the list to look at
 * something, forget the box is full, press Reconcile, and 12 batches are
 * stamped where 138 were meant. The count on the button was already the
 * smaller number and the bar already carried a chip, and neither of those is
 * a stop. Ryan called it on 8 Sep 2026: put it in the confirmation, which is
 * the one thing that is read immediately before the press.
 *
 * It says nothing at all when no filter is narrowing anything, so a normal
 * commit is not made to look dangerous.
 */
export default function confirmForm() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === 'yes') return;

            event.preventDefault();

            // The filter line LEADS. It is the part that can surprise; the
            // standing explanation below it is the same every time and is
            // already familiar to anybody pressing this button.
            const text = [filtered(form), form.dataset.confirmText || '']
                .filter(Boolean)
                .join('\n\n');

            const ok = await window.Agora.notify.confirm(form.dataset.confirm, {
                text,
                action: form.dataset.confirmAction || 'Continue',
                danger: form.hasAttribute('data-confirm-danger'),
            });

            if (!ok) return;

            form.dataset.confirmed = 'yes';
            form.submit();
        });
    });
}

/**
 * What a column filter is currently keeping out of this submission.
 *
 * Read off the table's own dataset rather than from a table-tools event: this
 * runs at the moment of the submit and needs an answer synchronously, and a
 * form should not have to subscribe to a table to ask one question about it.
 * A table with no tools carries no counters, which reads as nothing hidden —
 * which is the truth.
 */
function filtered(form) {
    const ids = (form.dataset.confirmFiltered || '')
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean);

    let hidden = 0;
    let total = 0;

    ids.forEach((id) => {
        const table = document.getElementById(id);

        if (!table) return;

        hidden += Number(table.dataset.ttHidden || 0);
        total += Number(table.dataset.ttTotal || 0);
    });

    if (hidden === 0) return '';

    return `A column filter is hiding ${hidden} of ${total} ${total === 1 ? 'row' : 'rows'}.`
        + ' Only the rows still on screen are included. Clear the filters first if you meant'
        + ' to act on all of them.';
}
