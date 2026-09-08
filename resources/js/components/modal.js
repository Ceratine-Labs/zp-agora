/**
 * Opening a <x-modal>, and filling it from the server.
 *
 * The native <dialog> already does the hard parts — top layer, backdrop, focus
 * trap, Escape, returning focus — so this adds only the two things it does
 * not:
 *
 *   1. `data-modal-open="ID"` on any control opens that dialog. Where the
 *      control also carries `data-modal-url`, the body is fetched from it.
 *   2. The body is fetched EVERY open, not cached. row-detail.js caches
 *      because a proposal's constituent rows do not change while you look at
 *      them; a configuration form is the opposite — the whole reason to open
 *      it is that somebody may have changed the thing since you last looked.
 *
 * The server returns HTML, not JSON: the formats live in App\Support\Format
 * and rebuilding them here is how the two drift apart.
 *
 * Clicking the backdrop closes. A dialog covers its own padding box, so the
 * test is whether the click landed outside the dialog's rectangle — comparing
 * event.target to the dialog itself reports the backdrop as the dialog.
 */
export default function modal() {
    const dialogs = document.querySelectorAll('[data-modal]');

    if (!dialogs.length) return;

    dialogs.forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;

            const box = dialog.getBoundingClientRect();
            const inside = event.clientX >= box.left && event.clientX <= box.right
                && event.clientY >= box.top && event.clientY <= box.bottom;

            if (!inside) dialog.close();
        });
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-modal-open]');

        if (!trigger) return;

        const dialog = document.getElementById(trigger.dataset.modalOpen);

        if (!dialog) return;

        event.preventDefault();
        open(dialog, trigger.dataset.modalUrl);
    });
}

async function open(dialog, url) {
    const body = dialog.querySelector('[data-modal-body]');

    if (url && body) {
        body.innerHTML = '<p class="muted">Loading…</p>';
    }

    dialog.showModal();

    if (!url || !body) return;

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
        });

        if (!response.ok) throw new Error(`The server answered ${response.status}.`);

        body.innerHTML = await response.text();

        // Focus the first thing worth typing into, so the dialog is usable
        // from the keyboard the moment it has content.
        body.querySelector('input:not([type=hidden]), select, textarea, button')?.focus();
    } catch (error) {
        body.innerHTML = `<p class="muted">${error.message || 'That could not be loaded.'}</p>`;
    }
}
