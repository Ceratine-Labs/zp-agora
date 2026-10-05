/**
 * Releasing a possible duplicate issue (D2) from holding its chain back, and
 * withdrawing a release (Ryan, 5 Oct 2026, PM decision 01a10b21).
 *
 * A chain carrying a D2 is held back from the stock recon commit until the
 * site corrects the issue in PumpIT or somebody releases it with a reason. The
 * press is a button; the reason is asked for in Agora's own dialog
 * (notify.ask) and posted with the lines it covers. One reason for the lot
 * when there are several — the precedent is "Force all close" on the recon
 * Suggestions tab (fec66f7).
 *
 * The markup contract, so any screen can mount it — the chain panel today, a
 * duplicates panel tomorrow — through
 * `stockrecon::partials.release-duplicates`:
 *
 *   <button type="button" data-dup-release="<POST url>"
 *           data-dup-lines="12,13"          the run line ids, or
 *           data-dup-grid="<grid key>"      the ticked rows of that grid
 *           data-dup-summary="…">           what the dialog says it covers
 *
 *   <button type="button" data-dup-withdraw="<POST url>"
 *           data-dup-summary="…">           asks, then withdraws one release
 *
 * DELEGATED FROM THE DOCUMENT, because the chain panel is a fragment that
 * row-detail.js fetches after load and puts inside the proposals table — and
 * that table sits inside the commit form. So the post is a form of its own,
 * built here and appended to the body, never the button's own form: a release
 * pressed inside the commit form must not submit the commit.
 *
 * The server refuses anything the dialog let through that it should not — a
 * reason too short to be checked later, a line that is not a D2 — so the
 * dialog's checks are a convenience, not the guard.
 */
const MIN_REASON = 10;

export default function duplicateRelease() {
    document.addEventListener('click', async (event) => {
        const release = event.target.closest('[data-dup-release]');
        const withdraw = event.target.closest('[data-dup-withdraw]');

        if (!release && !withdraw) return;

        event.preventDefault();

        const button = release || withdraw;

        // A second click while the first is still on its way is not a second request.
        if (button.dataset.posting === 'yes') return;

        if (release) {
            await askAndRelease(release);
        } else {
            await confirmAndWithdraw(withdraw);
        }
    });
}

async function askAndRelease(button) {
    const notify = window.Agora.notify;
    const lines = linesFor(button);

    if (lines.length === 0) {
        await notify.warn('Nothing to release', 'Tick the possible duplicates to release first.');
        return;
    }

    const count = lines.length === 1 ? 'this possible duplicate' : `these ${lines.length} possible duplicates`;
    const reason = await notify.ask(`Release ${count}?`, {
        text: [
            button.dataset.dupSummary || '',
            'Released, it stops holding its chain back: the next preview balances the chain and the commit '
            + 'writes it. Say what you checked — the issue book, the storeroom count or the delivery note. '
            + 'The reason is kept with your name, and the release can be withdrawn.',
        ].filter(Boolean).join('\n\n'),
        action: 'Release',
        placeholder: 'What the issue book, storeroom count or delivery note showed',
        maxlength: 400,
        minlength: MIN_REASON,
    });

    if (reason === null) return;

    post(button, button.dataset.dupRelease, { reason, 'lines[]': lines });
}

async function confirmAndWithdraw(button) {
    const ok = await window.Agora.notify.confirm('Withdraw this release?', {
        text: [
            button.dataset.dupSummary || '',
            'From the next preview the possible duplicate holds its chain back again, and nothing on the chain '
            + 'is written until the site corrects the issue or it is released again.',
        ].filter(Boolean).join('\n\n'),
        action: 'Withdraw',
        danger: true,
    });

    if (!ok) return;

    post(button, button.dataset.dupWithdraw, {});
}

/** The run line ids a press covers: named on the button, or ticked in a grid. */
function linesFor(button) {
    if (button.dataset.dupGrid) {
        const grid = document.querySelector(`[data-grid-key="${button.dataset.dupGrid}"]`);

        if (!grid) return [];

        // `:not(:disabled)` as bulk-selection.js has it: a row a column filter
        // hides has left the submission.
        return Array.from(grid.querySelectorAll('[data-check]:not(:disabled)'))
            .filter((box) => box.checked)
            .map((box) => box.value)
            .filter(Boolean);
    }

    return (button.dataset.dupLines || '')
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean);
}

/**
 * A plain form POST, as post-link.js makes one: the same round trip a page's
 * own button makes, so the controller, its permission and its refusals are
 * shared, and the answer comes back as the page.
 */
function post(button, url, fields) {
    button.dataset.posting = 'yes';

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.hidden = true;

    const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    add('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

    Object.entries(fields).forEach(([name, value]) => {
        (Array.isArray(value) ? value : [value]).forEach((v) => add(name, v));
    });

    document.body.appendChild(form);
    window.Agora.loader?.submitting(form);
    form.submit();
}
