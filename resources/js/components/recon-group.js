/**
 * Running one reconciliation area across every site, one site at a time.
 *
 * WHY THE BROWSER DRIVES IT. Twenty-six previews of a busy branch-month is
 * minutes of wall clock. A single request that did all of them either exceeds
 * the request timeout or shows a spinner for two minutes that cannot say which
 * site it is on — and if it fails at site nineteen, nothing says which
 * nineteen succeeded. So each site is its own small POST: the table fills in
 * as it goes, a site that refuses becomes a row rather than a dead page, and a
 * reload picks up wherever it stopped, because the server recomputes what is
 * outstanding from the runs it has actually recorded.
 *
 * SEQUENTIAL, NOT PARALLEL, and deliberately. Each preview runs a stored
 * procedure over the customer's 249 GB production database; twenty-six of them
 * at once is a load nobody asked us to put on a live system that people are
 * trading on. One at a time is also the honest progress indicator.
 *
 * The server returns the row's HTML, not JSON. The number formats live in
 * App\Support\Format and rebuilding them here is how the two drift apart —
 * the same contract row-detail.js works to.
 *
 * The markup contract:
 *
 *   <div data-recon-group
 *        data-group-branches="7,9,18"        the sites still to do, in order
 *        data-group-url="/app/recon/groups/{ref}/branches/0"
 *        data-group-total="26" data-group-done="3">
 *     <p data-group-status>…</p>
 *     <button data-group-start>…</button>
 *
 * and one <tr data-group-branch="{id}"> per site in the table below it.
 */
export default function reconGroup() {
    const panel = document.querySelector('[data-recon-group]');

    if (!panel) return;

    const button = panel.querySelector('[data-group-start]');
    const status = panel.querySelector('[data-group-status]');

    if (!button) return;

    button.addEventListener('click', () => run(panel, button, status), { once: true });

    /*
     * A FRESH GROUP STARTS ITSELF.
     *
     * The press that created it said "Preview every site", so asking for a
     * second press on the next page is asking twice for one decision — and
     * the page it lands on shows a stat strip of zeros above a button that is
     * easy to miss, which reads as a screen that has hung. Ryan reported
     * exactly that on live on 8 September 2026, on a group where the driver
     * was working perfectly and simply had not been told to go.
     *
     * `data-group-auto` is set only when NOTHING has been previewed yet. A
     * group somebody left half-done waits for the button instead: coming back
     * to a page and having it start moving on its own is the other failure,
     * and a resume is a decision rather than a continuation.
     */
    if (panel.dataset.groupAuto === '1') {
        run(panel, button, status);
    }
}

async function run(panel, button, status) {
    const branches = (panel.dataset.groupBranches || '')
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean);

    const total = Number(panel.dataset.groupTotal || branches.length);
    let done = Number(panel.dataset.groupDone || 0);

    button.disabled = true;
    button.textContent = 'Previewing…';

    for (const branch of branches) {
        const row = document.querySelector(`tr[data-group-branch="${branch}"]`);
        const name = row ? row.cells[1]?.textContent.trim() : branch;

        say(status, `Previewing ${name} — ${done + 1} of ${total}.`);
        mark(row, 'Running…');

        try {
            const html = await preview(panel.dataset.groupUrl, branch);
            replace(row, html);
        } catch (error) {
            // A site that could not even be asked is not a site that
            // reconciled. It is left saying so, and the rest still go: twenty-
            // six sites is twenty-six answers.
            mark(row, error.message || 'Could not run this site.');
        }

        done += 1;
    }

    say(status, `Every site attempted — ${total} of ${total}. Tick the ones to post.`);
    button.remove();

    // The footer's count and the tick-all box were rendered against an empty
    // group; a reload is the cheapest way to have the server say what is
    // postable rather than counting it twice in two languages.
    window.setTimeout(() => window.location.reload(), 400);
}

async function preview(template, branch) {
    // The route was generated with a placeholder branch of 0, so the real id
    // is substituted rather than built by string concatenation here — the URL
    // shape stays the router's business.
    const url = template.replace(/\/0$/, `/${branch}`);

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'text/html',
        },
    });

    if (!response.ok) {
        throw new Error(`The server refused this site (${response.status}).`);
    }

    return response.text();
}

function replace(row, html) {
    if (!row) return;

    const table = document.createElement('table');
    table.innerHTML = `<tbody>${html.trim()}</tbody>`;

    const fresh = table.querySelector('tr');

    if (fresh) row.replaceWith(fresh);
}

function mark(row, text) {
    const cell = row?.querySelector('[data-group-pending]');

    if (cell) cell.textContent = text;
}

function say(status, text) {
    if (status) status.textContent = text;
}
