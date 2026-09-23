/**
 * "Match all strong" and "Match all possible" on the recon Suggestions tab.
 *
 * Each suggestion is already a form that posts one match — the same route,
 * procedure and ledger as a match made by hand. This presses one tier's forms
 * one after another, so a month of suggestions is one decision instead of two
 * hundred.
 *
 * TWO PRESSES, NEVER ONE. Possible is the weaker claim — another pairing
 * wanted some of its rows, or the batch number on the bank line is not on its
 * deposits — so it has its own button and its own confirmation, which counts
 * those reasons out loud. Ryan asked for it on 23 Sep 2026 at Ngwenya, where
 * the till files deposits under a different merchant and batch numbering from
 * the bank's and every tie there is therefore possible. No two suggestions
 * share a row, so the two presses can never claim a row twice.
 *
 * SEQUENTIAL, NOT PARALLEL, for the reason recon-group.js gives: every match
 * runs a procedure that writes to the customer's live database, and one at a
 * time is also the honest progress indicator.
 *
 * EACH ONE IS ITS OWN ANSWER. The procedure re-reads every row, so a
 * suggestion whose rows something else reconciled since the page was drawn is
 * refused, and that refusal is written into its own row rather than stopping
 * the rest. The server answers JSON here and HTML for a single press.
 *
 * WHAT IS ON SCREEN. A row a column filter hides has its inputs disabled by
 * table-tools.js; those are left alone, exactly as a filtered commit leaves
 * them. A row on another PAGE is still in scope — a page is a way of looking,
 * not a scope — and the confirmation says how many there are.
 *
 * The markup contract:
 *
 *   <div data-suggest-bulk data-suggest-live="1">
 *     <button data-suggest-run="strong">…</button>
 *     <button data-suggest-run="possible">…</button>
 *     <span data-suggest-status></span>
 *   </div>
 *   <td data-suggest-state>
 *     <form data-suggest-kind="strong|possible" data-amount="…" data-caution="…">…</form>
 *   </td>
 */
export default function reconSuggest() {
    const panel = document.querySelector('[data-suggest-bulk]');

    if (!panel) return;

    const buttons = [...panel.querySelectorAll('[data-suggest-run]')];
    const status = panel.querySelector('[data-suggest-status]');

    buttons.forEach((button) => {
        button.addEventListener('click', () => run(panel, button, buttons, status));
    });
}

async function run(panel, button, buttons, status) {
    const kind = button.dataset.suggestRun;
    const forms = [...document.querySelectorAll(`form[data-suggest-kind="${kind}"]`)]
        .filter((form) => !form.querySelector('[type="submit"]')?.disabled);

    if (forms.length === 0) {
        say(status, `No ${kind} suggestion is on screen — a column filter may be hiding them.`);
        return;
    }

    const live = panel.dataset.suggestLive === '1';
    const total = forms.reduce((sum, form) => sum + Number(form.dataset.amount || 0), 0);
    const noun = `${forms.length} ${kind} ${forms.length === 1 ? 'suggestion' : 'suggestions'}`;

    const ok = await window.Agora.notify.confirm(
        `${live ? 'Match' : 'Record'} ${noun}${live ? ' in PumpIT' : ''}?`,
        {
            text: [
                kind === 'possible' ? weaker(forms) : '',
                `${money(total)} on each side. Each is matched on its own — its rows are re-read first, `
                    + 'anything reconciled since this list was drawn is refused rather than stamped over, and '
                    + 'each gets its own batch number and run, reversible from that run.',
            ].filter(Boolean).join('\n\n'),
            action: live ? `Match all ${kind}` : `Record all ${kind}`,
            danger: live || kind === 'possible',
        },
    );

    if (!ok) return;

    // Both presses wait while one runs: they share the page and the status line.
    buttons.forEach((b) => { b.disabled = true; });

    let done = 0;
    let matched = 0;
    let refused = 0;

    for (const form of forms) {
        done += 1;
        say(status, `Matching ${done} of ${forms.length}…`);

        const cell = form.closest('[data-suggest-state]');

        try {
            const answer = await post(form);

            if (answer.ok) {
                matched += 1;
                settle(cell, `<a href="${escapeAttr(answer.run)}">Batch ${escapeHtml(String(answer.batch))}</a>`, 'good');
            } else {
                refused += 1;
                settle(cell, escapeHtml(answer.message || 'Refused.'), 'crit');
            }
        } catch (error) {
            // A match that could not even be asked is not one that happened.
            // Said in its row, and the rest still go.
            refused += 1;
            settle(cell, escapeHtml(error.message || 'Could not be sent.'), 'crit');
        }
    }

    say(status, `${matched} ${kind} matched${refused ? `, ${refused} refused — the reason is in each row` : ''}.`);

    // What is left has changed, and only the procedure can say what it is
    // now. Offered rather than done, so the rows above can still be read. The
    // other tier's press stays usable: its rows were never in this run.
    const again = document.createElement('a');
    again.href = window.location.href;
    again.className = 'btn-ghost sm';
    again.textContent = 'Suggest again for what is left';
    button.replaceWith(again);
    buttons.filter((b) => b !== button).forEach((b) => { b.disabled = false; });
}

/**
 * Why these are only possible, counted — the line the confirmation leads with.
 * The reasons are the procedure's own words, carried on each form.
 */
function weaker(forms) {
    const counts = new Map();

    forms.forEach((form) => {
        const reason = form.dataset.caution || 'Possible';
        counts.set(reason, (counts.get(reason) || 0) + 1);
    });

    const lines = [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([reason, n]) => `${n} × ${reason}.`);

    return ['These are the weaker readings — the amounts tie to the cent, but:', ...lines].join('\n');
}

async function post(form) {
    const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    const answer = await response.json().catch(() => null);

    if (answer) return answer;

    throw new Error(`The server answered ${response.status}.`);
}

function settle(cell, html, tone) {
    if (!cell) return;

    cell.innerHTML = `<span class="chip ${tone} tone-${tone}" style="white-space:normal">${html}</span>`;
}

function say(status, text) {
    if (status) status.textContent = text;
}

function money(value) {
    return window.Agora?.format?.R ? window.Agora.format.R(value) : `R${value.toFixed(2)}`;
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
}
