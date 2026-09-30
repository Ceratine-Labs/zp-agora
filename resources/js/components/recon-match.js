/**
 * The manual reconcile workbench — the live half of <x-two-pane-recon>.
 *
 * Four behaviours, and each exists because the screen is unusable without it:
 *
 *   1. THE RUNNING TOTALS. "Do these two sides agree" is the only question
 *      this screen asks. Making somebody add up two columns of ticked rows to
 *      answer it would be absurd, so the strip above the panes reports the two
 *      sums and their difference as boxes are ticked.
 *
 *   2. THE REASON APPEARS WHEN IT IS NEEDED. A match whose sides do not
 *      balance is allowed — sometimes that is the truth of it — but only with
 *      a reason, and the procedure refuses without one. Showing the field only
 *      once the difference is non-zero is what makes that rule visible at the
 *      moment it applies instead of as a rejection afterwards.
 *
 *   3. CLICKING A COLOUR TAKES THE WHOLE GROUP. A coloured row means its
 *      reference appears on both sides; the pair is nearly always the whole
 *      group, on both sides at once. Ctrl/Cmd-click on a coloured row selects
 *      every row carrying that key, left and right.
 *
 *   4. THE SPLIT BY CASHIER, on cash bags only (27 Sep 2026). A bag belongs to
 *      the cashier who dropped it, so the ticked bags are grouped by cashier in
 *      the panel above the two sides, each with a share of the variance and
 *      what that leaves them accountable for. The share is DERIVED pro rata by
 *      what each banked (Ryan, 9 Sep 2026); the clerk confirms it by matching,
 *      or changes a share, and the Match button stays disabled until the
 *      shares add up to the variance. The split posts as one hidden field of
 *      JSON and is named in the confirmation dialog, the last thing read
 *      before the press. The panel only exists where the rows carry a cashier,
 *      so the other four areas are exactly as they were.
 *
 *      agora.usp_Recon_ManualMatch is the AUTHORITY on the derived share and
 *      refuses a split that is not the bags' or does not add up. proRata() and
 *      derive() below mirror its arithmetic — whole cents, half away from
 *      zero, the remainder on the line that banked most — only so the default
 *      on screen is the one it will call 'derived'. If they ever disagree, the
 *      procedure is right.
 *
 * Money is added up in whole CENTS. Summing floats and rounding afterwards is
 * how a split that balances comes out a cent short on screen.
 *
 * The formatting goes through window.Agora.format, which is the twin of
 * App\Support\Format — rebuilding the number format here is how the two drift.
 */
export default function reconMatch(root = document) {
    // `root` is the recon centre's Manual tab when the pane arrives as a
    // fragment; the whole document on the standalone page.
    const pane = root.querySelector('[data-two-pane]:not([data-two-pane-ready])');

    if (!pane) return;

    pane.setAttribute('data-two-pane-ready', '');

    const form = pane.closest('form');
    const strip = root.querySelector('[data-match-totals]');
    const reason = root.querySelector('[data-match-reason]');
    const submit = root.querySelector('[data-match-submit]');
    const panel = root.querySelector('[data-match-allocation]');
    const split = panel ? splitPanel(panel, form) : null;

    const recount = () => report(pane, strip, reason, submit, split);

    pane.addEventListener('change', (event) => {
        if (event.target.matches('[data-pane-pick]')) recount();
    });

    // Select-all, per side. Each pane has its own: ticking every bank line is
    // a normal thing to want; ticking every row on the screen never is.
    pane.querySelectorAll('[data-pane-all]').forEach((box) => {
        box.addEventListener('change', () => {
            const side = box.dataset.paneAll;
            // Only the rows a column filter has left on screen: their boxes
            // are the ones still in the submission (table-tools.js disables
            // the rest), so ticking a disabled one would promise a row the
            // procedure is never sent.
            pane.querySelectorAll(`[data-pane-pick="${side}"]:not(:disabled)`).forEach((pick) => {
                pick.checked = box.checked;
            });
            recount();
        });
    });

    // Ctrl/Cmd-click a coloured row to take its whole group on both sides.
    pane.addEventListener('click', (event) => {
        if (!event.ctrlKey && !event.metaKey) return;

        const row = event.target.closest('[data-pair-key]');
        const key = row?.dataset.pairKey;

        if (!key) return;

        event.preventDefault();

        pane.querySelectorAll(`[data-pair-key="${CSS.escape(key)}"]`).forEach((match) => {
            const pick = match.querySelector('[data-pane-pick]:not(:disabled)');
            if (pick) pick.checked = true;
        });

        recount();
    });

    if (panel) {
        // A share typed by hand: the lines stay as they are, the totals, the
        // state line and the posted JSON follow the typing.
        panel.addEventListener('input', (event) => {
            if (event.target.matches('[data-alloc-share]')) recount();
        });

        panel.querySelector('[data-alloc-reset]')?.addEventListener('click', () => {
            split.reset();
            recount();
        });
    }

    if (form) form.addEventListener('reset', () => window.setTimeout(recount, 0));

    // A filter takes rows out of the submission, so the two totals and the
    // difference are about different rows than they were a moment ago.
    pane.addEventListener('table-tools:change', recount);

    recount();
}

function report(pane, strip, reason, submit, split) {
    const bank = sum(pane, 'bank');
    const mops = sum(pane, 'mops');
    // Bank less deposits (Ryan, 27 Sep 2026): the sign of the split below,
    // and of every recon screen — negative is money the bank did not receive.
    const difference = (bank.cents - mops.cents) / 100;

    // Judged in cents: two sums of money held as floats can differ by 1e-10,
    // and a screen that then demands a reason for a match that balances is a
    // screen nobody trusts.
    const off = mops.cents !== bank.cents;

    if (strip) {
        const cells = strip.querySelectorAll('.stat');

        write(cells[0], money(bank.cents / 100), `${bank.count} ${bank.count === 1 ? 'line' : 'lines'}`);
        write(cells[1], money(mops.cents / 100), `${mops.count} ${mops.count === 1 ? 'row' : 'rows'}`);
        write(cells[2], money(difference), off ? 'a reason is required' : 'the two sides agree');
    }

    if (reason) {
        reason.hidden = !off;

        const field = reason.querySelector('input');

        // Only required while it is on screen — a hidden required field is a
        // form that cannot be submitted and does not say why.
        if (field) field.required = off;
    }

    // The variance the split shares out is bank less bags: what the bank
    // credited beyond (or short of) what the bags were banked as.
    const splitAddsUp = split ? split.update(bags(pane), bank.cents - mops.cents) : true;

    if (submit) submit.disabled = bank.count === 0 || mops.count === 0 || !splitAddsUp;
}

function sum(pane, side) {
    const picked = pane.querySelectorAll(`[data-pane-pick="${side}"]:checked:not(:disabled)`);
    let cents = 0;

    picked.forEach((pick) => {
        cents += toCents(pick.closest('[data-amount]')?.dataset.amount);
    });

    return { count: picked.length, cents };
}

/**
 * The ticked deposits that name a cashier — every cash bag, and nothing on
 * the other four areas, whose rows carry no `data-cashier-code`. An EMPTY code
 * is a bag with no drop-safe row: it stays in the list, as "no cashier
 * recorded", because a split that dropped it would not add up to the bags.
 */
function bags(pane) {
    return [...pane.querySelectorAll('[data-pane-pick="mops"]:checked:not(:disabled)')]
        .map((pick) => pick.closest('tr'))
        .filter((row) => row && row.hasAttribute('data-cashier-code'))
        .map((row) => ({
            code: row.dataset.cashierCode ?? '',
            name: row.dataset.cashierName || null,
            cents: toCents(row.dataset.amount),
            drop: row.dataset.dropSafe ? toCents(row.dataset.dropSafe) : null,
        }));
}

/**
 * The split panel: draws a line per cashier, holds what the clerk typed, and
 * keeps the posted JSON and the confirmation dialog in step with the screen.
 *
 * `update()` is called on every recount. It REDRAWS only when what is ticked
 * (or the bank side) has changed — the derived split is for those bags, and a
 * share typed for a different set of bags would not add up to anything — and
 * otherwise re-reads the typed shares and re-settles the totals. It answers
 * whether the split adds up, which is what gates the Match button.
 */
function splitPanel(panel, form) {
    const json = panel.querySelector('[data-alloc-json]');
    const body = panel.querySelector('tbody');
    const head = panel.querySelector('[data-alloc-head]');
    const state = panel.querySelector('[data-alloc-state]');
    const resetButton = panel.querySelector('[data-alloc-reset]');

    // The confirmation's standing text, kept so the split can be appended to
    // it and taken off again when nothing is ticked.
    if (form && form.dataset.confirmTextBase === undefined) {
        form.dataset.confirmTextBase = form.dataset.confirmText ?? '';
    }

    let signature = null;
    let lines = [];
    let variance = 0;

    const draw = () => {
        body.replaceChildren(...lines.map((line, i) => lineRow(line, i)), totalRow(lines));
    };

    const settle = () => {
        lines.forEach((line, i) => {
            const input = body.querySelector(`[data-alloc-share="${i}"]`);
            line.share = input ? readCents(input.value) : null;

            const cell = body.querySelector(`[data-alloc-accountable="${i}"]`);
            if (cell) cell.textContent = line.share === null ? '—' : money((line.banked + line.share) / 100);
        });

        const missing = lines.some((line) => line.share === null);
        const shares = missing ? null : lines.reduce((t, line) => t + line.share, 0);
        const addsUp = !missing && shares === variance;
        const byHand = lines.some((line) => line.share !== line.derived);

        const shareTotal = body.querySelector('[data-alloc-share-total]');
        const accountableTotal = body.querySelector('[data-alloc-accountable-total]');
        const banked = lines.reduce((t, line) => t + line.banked, 0);

        if (shareTotal) shareTotal.textContent = shares === null ? '—' : money(shares / 100);
        if (accountableTotal) accountableTotal.textContent = shares === null ? '—' : money((banked + shares) / 100);

        if (resetButton) resetButton.hidden = !byHand;

        if (state) {
            state.replaceChildren(stateLine(addsUp, missing, shares, variance, byHand));
        }

        if (head) head.textContent = headLine(lines);

        json.value = JSON.stringify(lines.map((line) => ({
            code: line.code === '' ? null : line.code,
            banked: plain(line.banked),
            share: line.share === null ? null : plain(line.share),
        })));

        if (form) {
            form.dataset.confirmText = [form.dataset.confirmTextBase, dialogLine(lines, byHand)]
                .filter(Boolean)
                .join('\n\n');
        }

        return addsUp;
    };

    return {
        update(ticked, varianceCents) {
            if (ticked.length === 0) {
                panel.hidden = true;
                json.value = '';
                signature = null;
                lines = [];

                if (form) form.dataset.confirmText = form.dataset.confirmTextBase;

                return true;
            }

            panel.hidden = false;

            const now = JSON.stringify([varianceCents, ticked.map((b) => [b.code, b.cents, b.drop]).sort()]);

            if (now !== signature) {
                signature = now;
                variance = varianceCents;
                lines = derive(ticked, varianceCents);
                draw();
            }

            return settle();
        },

        /** Every share back to the derived one. */
        reset() {
            lines.forEach((line, i) => {
                const input = body.querySelector(`[data-alloc-share="${i}"]`);
                if (input) input.value = plain(line.derived);
            });
        },
    };
}

/**
 * One line per cashier, and the derived share of each — the mirror of step 4b
 * in agora.usp_Recon_ManualMatch.
 *
 * Grouped by code; the bags with no cashier recorded share the empty code and
 * so one line. Ordered by what was banked, most first, ties by the code in
 * ordinal order (the procedure's COLLATE Latin1_General_BIN2) — the first line
 * takes the rounding remainder, so the shares add up to the variance exactly.
 */
function derive(ticked, varianceCents) {
    const byCode = new Map();

    ticked.forEach((bag) => {
        const line = byCode.get(bag.code)
            ?? { code: bag.code, name: null, bags: 0, banked: 0, drop: 0, dropBags: 0 };

        line.bags += 1;
        line.banked += bag.cents;
        if (bag.drop !== null) {
            line.drop += bag.drop;
            line.dropBags += 1;
        }
        if (!line.name && bag.name) line.name = bag.name;

        byCode.set(bag.code, line);
    });

    const lines = [...byCode.values()].sort((a, b) => b.banked - a.banked || ordinal(a.code, b.code));
    const total = lines.reduce((t, line) => t + line.banked, 0);

    lines.forEach((line) => {
        line.derived = proRata(varianceCents, line.banked, total);
    });

    if (lines.length > 0) {
        lines[0].derived += varianceCents - lines.reduce((t, line) => t + line.derived, 0);
    }

    lines.forEach((line) => {
        line.share = line.derived;
    });

    return lines;
}

/**
 * variance x banked / total in whole cents, half away from zero, by integer
 * arithmetic — BigInt, because the product of two rand amounts in cents can
 * pass 2^53. The same expression as the procedure's:
 *   SIGN(n) * SIGN(d) * ((2 * ABS(n) + ABS(d)) / (2 * ABS(d)))
 */
function proRata(varianceCents, bankedCents, totalCents) {
    if (totalCents === 0) return 0;

    const n = BigInt(varianceCents) * BigInt(bankedCents);
    const d = BigInt(totalCents);
    const an = n < 0n ? -n : n;
    const ad = d < 0n ? -d : d;
    const q = (2n * an + ad) / (2n * ad);

    return Number((n < 0n) !== (d < 0n) ? -q : q);
}

function ordinal(a, b) {
    if (a < b) return -1;
    return a > b ? 1 : 0;
}

function lineRow(line, i) {
    const tr = document.createElement('tr');

    tr.append(
        cashierCell(line),
        cell(String(line.bags), 'num'),
        cell(money(line.banked / 100), 'num'),
        dropCell(line),
        shareCell(line, i),
        cell(money((line.banked + line.share) / 100), 'num', { allocAccountable: String(i) }),
    );

    return tr;
}

function totalRow(lines) {
    const tr = document.createElement('tr');
    const bagCount = lines.reduce((t, line) => t + line.bags, 0);
    const banked = lines.reduce((t, line) => t + line.banked, 0);
    const dropBags = lines.reduce((t, line) => t + line.dropBags, 0);
    const drop = lines.reduce((t, line) => t + line.drop, 0);

    // The two totals the typing moves are filled in by settle(); the data
    // attribute sits on the <strong>, so writing the figure keeps the weight.
    tr.append(
        strongCell('Total', ''),
        strongCell(String(bagCount), 'num'),
        strongCell(money(banked / 100), 'num'),
        dropTotal(dropBags, bagCount, drop),
        strongCell('', 'num', { allocShareTotal: '' }),
        strongCell('', 'num', { allocAccountableTotal: '' }),
    );

    return tr;
}

/**
 * Never a blank cashier. The same three readings the deposit table gives: a
 * resolved name with its code, a code the employee list does not know, and
 * bags with no drop-safe row at all.
 */
function cashierCell(line) {
    const td = document.createElement('td');

    if (line.code === '') {
        const span = document.createElement('span');
        span.className = 'muted';
        span.title = 'These bags have no BRN_DropSafe row, so nothing records who dropped them';
        span.textContent = 'No cashier recorded';
        td.append(span);

        return td;
    }

    if (line.name) {
        td.append(document.createTextNode(`${line.name} `), mono(line.code));
    } else {
        td.append(mono(line.code), document.createTextNode(' '),
            chip('warn', 'not on the employee list',
                'The drop safe names this code, and BRN_Employee has no one by it at this site'));
    }

    return td;
}

/**
 * What was declared at the safe, beside what was banked (Ryan, 9 Sep 2026: the
 * split is on the banked amount, and the drop safe is SHOWN so an adjusted bag
 * is not reconciled away silently).
 */
function dropCell(line) {
    const td = cell('', 'num');

    if (line.dropBags === 0) {
        td.textContent = '—';

        return td;
    }

    if (line.dropBags === line.bags && line.drop !== line.banked) {
        td.append(
            chip('warn', 'adjusted', `Declared ${money(line.drop / 100)} at the drop safe; banked as ${money(line.banked / 100)}`),
            document.createTextNode(' '),
        );
    }

    td.append(document.createTextNode(money(line.drop / 100)));

    if (line.dropBags < line.bags) {
        const note = document.createElement('span');
        note.className = 'muted';
        note.style.fontSize = '11px';
        note.textContent = ` (${line.dropBags} of ${line.bags} bags)`;
        td.append(note);
    }

    return td;
}

function shareCell(line, i) {
    const td = cell('', 'num');
    const wrap = document.createElement('div');
    const input = document.createElement('input');

    // .field styles the control; it carries no label because the column head
    // is the label and the aria-label names the cashier.
    wrap.className = 'field';
    wrap.style.alignItems = 'flex-end';

    input.type = 'number';
    input.step = '0.01';
    input.inputMode = 'decimal';
    input.value = plain(line.share);
    input.style.maxWidth = '130px';
    input.style.textAlign = 'right';
    input.dataset.allocShare = String(i);
    input.setAttribute('aria-label', `Share of the variance for ${label(line)}`);

    wrap.append(input);
    td.append(wrap);

    return td;
}

function stateLine(addsUp, missing, shares, variance, byHand) {
    if (missing) {
        return chip('crit', 'Every cashier needs a share, zero included');
    }

    if (!addsUp) {
        const span = document.createElement('span');
        span.append(
            chip('crit', 'Does not add up'),
            document.createTextNode(
                ` The shares come to ${money(shares / 100)} and the variance is ${money(variance / 100)}: `
                + `${money((variance - shares) / 100)} is accounted to nobody. The match waits until it is.`,
            ),
        );

        return span;
    }

    const span = document.createElement('span');
    span.append(
        chip('good', byHand ? 'Adds up — changed by hand' : 'Adds up'),
        document.createTextNode(variance === 0
            ? ' The bank and the bags agree, so nobody carries a share.'
            : ` The shares account for the whole variance of ${money(variance / 100)}, bank less bags`
              + (byHand ? '.' : ', pro rata by what each cashier banked.')),
    );

    return span;
}

function headLine(lines) {
    const count = lines.reduce((t, line) => t + line.bags, 0);
    const bagWord = count === 1 ? 'bag' : 'bags';

    if (lines.length === 1 && lines[0].code === '') {
        return count === 1
            ? 'This bag records nobody who dropped it — confirm by matching'
            : `None of the ${count} bags records who dropped it — confirm by matching`;
    }

    if (lines.length === 1) {
        return count === 1
            ? `Dropped by ${label(lines[0])} — confirm by matching`
            : `All ${count} bags dropped by ${label(lines[0])} — confirm by matching`;
    }

    return `${count} ${bagWord}, ${lines.length} cashiers — confirm the split by matching, or change a share`;
}

/** The split, as the confirmation dialog names it before the press. */
function dialogLine(lines, byHand) {
    const describe = (line) => `${label(line)}: banked ${money(line.banked / 100)}, `
        + `share ${line.share === null ? '—' : money(line.share / 100)}, `
        + `accountable ${line.share === null ? '—' : money((line.banked + line.share) / 100)}`;

    if (lines.length === 1) {
        return `The cash is accounted to ${describe(lines[0])}.`;
    }

    const shown = lines.slice(0, 10).map((line) => `· ${describe(line)}`);

    if (lines.length > 10) shown.push(`· and ${lines.length - 10} more, as shown on the screen`);

    return `The cash is split between ${lines.length} cashiers${byHand ? ', as changed by hand' : ''}:\n${shown.join('\n')}`;
}

function label(line) {
    if (line.code === '') return 'no cashier recorded';

    return line.name ? `${line.name} (${line.code})` : line.code;
}

function cell(text, className, data = {}) {
    const td = document.createElement('td');

    if (className) td.className = className;
    td.textContent = text;
    Object.assign(td.dataset, data);

    return td;
}

/** The drop-safe total says so when some bags had no drop-safe row. */
function dropTotal(dropBags, bagCount, drop) {
    const td = strongCell(dropBags === 0 ? '—' : money(drop / 100), 'num');

    if (dropBags > 0 && dropBags < bagCount) {
        const note = document.createElement('span');
        note.className = 'muted';
        note.style.fontSize = '11px';
        note.textContent = ` (${dropBags} of ${bagCount} bags)`;
        td.append(note);
    }

    return td;
}

function strongCell(text, className, data = {}) {
    const td = cell('', className);
    const b = document.createElement('strong');

    b.textContent = text;
    Object.assign(b.dataset, data);
    td.append(b);

    return td;
}

function mono(text) {
    const span = document.createElement('span');
    span.className = 'mono muted';
    span.style.fontSize = '11px';
    span.textContent = text;

    return span;
}

/** The markup <x-chip :dot="false"> draws, for a chip made in the browser. */
function chip(tone, text, title = '') {
    const span = document.createElement('span');
    span.className = `chip ${tone} tone-${tone}`;
    span.textContent = text;
    if (title) span.title = title;

    return span;
}

/** A money string ("1234.5600", "-12.3") to whole cents. */
function toCents(value) {
    const x = Number(value ?? 0);

    if (!Number.isFinite(x)) return 0;

    return Math.sign(x) * Math.round(Math.abs(x) * 100);
}

/** What the clerk typed, in cents — or null for anything not rand and cents. */
function readCents(value) {
    const text = String(value ?? '').trim();

    return /^-?\d+(\.\d{1,2})?$/.test(text) ? toCents(text) : null;
}

/** Cents as a plain decimal for the posted JSON and the inputs: "-12.30". */
function plain(cents) {
    const a = Math.abs(cents);

    return `${cents < 0 ? '-' : ''}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`;
}

/**
 * The rand format, through the twin of App\Support\Format.
 *
 * `R` is its name in that module — the same function the PHP side renders
 * every other figure on this screen with, so a total that appears while boxes
 * are ticked is formatted identically to one the server drew.
 */
function money(value) {
    return window.Agora?.format?.R ? window.Agora.format.R(value) : `R${value.toFixed(2)}`;
}

function write(cell, value, note) {
    if (!cell) return;

    const big = cell.querySelector('.v');
    const small = cell.querySelector('.n');

    if (big) big.textContent = value;
    if (small) small.textContent = note;
}
