/**
 * A table's scroll frame ends where the window ends.
 *
 * `.table-scroll` used to be `max-height: calc(100dvh - 230px)` on every
 * screen: a guess at "the app bar, the scope bar and a card head". A page with
 * more above its table than that — the stock recon run carries a page head,
 * tabs, an action bar and two pill strips, about 580px — got a frame that ran
 * off the bottom of the window, so the page scrolled AND the table scrolled
 * (Ryan, 12 Nov 2026: "got body scroll, table scroll, a whole bunch of
 * scrolls and depending on the size of the screen it doesnt display well").
 *
 * This measures instead of guessing. For each visible frame it writes
 * `--table-max-h` as the room between the frame's top and the bottom of the
 * window, less what sits under the frame (pager, table footer) and the page's
 * own tail, so on a screen with room the table is the ONLY thing that scrolls.
 * Where the chrome above leaves too little room for that (a short window, a
 * table far down a page) the frame instead takes one whole window less the
 * pinned chrome: the page scrolls once to bring the table up, and the table
 * then fills the screen.
 *
 * Left alone: a frame whose screen set `--table-max-h` itself (two panes, a
 * drawer), a table nested in another table's row, and a phone, where the
 * stylesheet's own value stands. A frame whose content is shorter than the
 * room is unaffected either way: max-height only engages above it.
 */
const MIN_ROOM = 280;      // below this, a frame to the window's edge is a slot
const TAIL_MAX = 120;      // the page's own padding and footer under the block
const PHONE = 720;

let wired = false;
let queued = false;

function fit() {
    queued = false;
    const phone = window.innerWidth <= PHONE;
    const page = document.documentElement;

    document.querySelectorAll('.table-scroll').forEach((scroll) => {
        if (scroll.parentElement?.closest('.table-scroll')) return;

        if (phone || scroll.offsetParent === null) {
            if (scroll.dataset.tableFit) {
                scroll.style.removeProperty('--table-max-h');
                delete scroll.dataset.tableFit;
            }
            return;
        }

        // The screen's own value wins. Ours is written on the frame itself, so
        // one read from the parent is the caller's and never an echo of ours.
        if (!scroll.dataset.tableFit
            && getComputedStyle(scroll.parentElement).getPropertyValue('--table-max-h').trim() !== '') {
            return;
        }

        const rect = scroll.getBoundingClientRect();
        const block = scroll.closest('.table-block') ?? scroll;
        const blockBottom = block.getBoundingClientRect().bottom;
        const under = Math.max(0, blockBottom - rect.bottom);
        const tail = Math.min(TAIL_MAX, Math.max(0, page.scrollHeight - (blockBottom + window.scrollY)));
        const top = rect.top + window.scrollY;

        let room = window.innerHeight - top - under - tail;

        if (room < MIN_ROOM) {
            // One window, less whatever stays pinned above the rows: the app
            // bar and the scope bar always, and a pinned action bar where the
            // table has one.
            const appbar = document.querySelector('.appbar');
            const scopebar = document.querySelector('.scopebar');
            const chrome = (appbar?.offsetHeight ?? 52) + (scopebar?.offsetHeight ?? 0);
            const bar = scroll.closest('.card, main, body')?.querySelector('.action-bar.is-sticky');
            const pinned = chrome + (bar && getComputedStyle(bar).position === 'sticky' ? bar.offsetHeight : 0);
            // Less the tail too: at the end of the page the frame's top must
            // still clear the pinned bar, or the bar sits on the table's head.
            room = Math.max(MIN_ROOM - 60, window.innerHeight - pinned - under - tail - 4);
        }

        const value = `${Math.floor(room)}px`;
        if (scroll.style.getPropertyValue('--table-max-h') !== value) {
            scroll.style.setProperty('--table-max-h', value);
        }
        scroll.dataset.tableFit = '1';
    });
}

function queue() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(fit);
}

export default function tableFit() {
    if (!wired) {
        wired = true;
        window.addEventListener('resize', queue, { passive: true });
        // Anything that moves a frame — pills arriving, a notice closing, a
        // tab switching, a details opening — changes the body's height.
        if ('ResizeObserver' in window) new ResizeObserver(queue).observe(document.body);
        document.addEventListener('click', () => setTimeout(queue, 0), true);
    }
    queue();
}
