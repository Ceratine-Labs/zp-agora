/**
 * The page's one tooltip.
 *
 * A singleton, because a daily chart with 31 bars must not mount 31 tooltip
 * elements, and because a tooltip that has to flip when it would run off the
 * right or the bottom edge needs a real element it can measure — which rules
 * out a CSS pseudo-element.
 *
 * Two ways in, and both end at the same element:
 *
 *   - `data-tip="…"` on anything. Shown on hover AND on focus. The mockup's
 *     was hover-only, which is a tooltip that nobody navigating by keyboard
 *     can read, and on a phone there is no hover at all — hence the focus
 *     path, and hence the rule that a tooltip only ever repeats something
 *     already written somewhere permanent.
 *   - `window.Agora.tip.show(event, html)` and `.hide()`, for a chart drawing
 *     its own rows into it.
 *
 * Escape closes it, and so does a scroll: a tip anchored to a pointer position
 * is wrong the instant the page moves under it.
 */
export default function tip(root = document) {
    const host = root.getElementById ? root.getElementById('tip') : document.getElementById('tip');
    if (! host) return null;

    /** Place the box near a point, flipping rather than overflowing. */
    function place(x, y) {
        const box = host.getBoundingClientRect();
        const gap = 14;

        let left = x + gap;
        let top = y + gap;

        if (left + box.width > window.innerWidth - 8) left = x - box.width - gap;
        if (top + box.height > window.innerHeight - 8) top = y - box.height - gap;

        host.style.left = `${Math.max(8, left)}px`;
        host.style.top = `${Math.max(8, top)}px`;
    }

    function show(source, html) {
        host.innerHTML = html;
        host.hidden = false;
        host.setAttribute('aria-hidden', 'false');

        // A pointer event knows where it is; a focus event does not, so the
        // element's own box is the anchor.
        if (source && typeof source.clientX === 'number' && (source.clientX || source.clientY)) {
            place(source.clientX, source.clientY);
        } else {
            const target = source?.target ?? source;
            const rect = target?.getBoundingClientRect?.();
            if (rect) place(rect.left, rect.bottom);
        }
    }

    function hide() {
        host.hidden = true;
        host.setAttribute('aria-hidden', 'true');
    }

    function fromElement(event) {
        const el = event.target.closest?.('[data-tip]');
        if (! el) return;
        show(event, el.dataset.tip);
    }

    document.addEventListener('mouseover', fromElement);
    document.addEventListener('focusin', fromElement);
    document.addEventListener('mouseout', (event) => {
        if (event.target.closest?.('[data-tip]')) hide();
    });
    document.addEventListener('focusout', (event) => {
        if (event.target.closest?.('[data-tip]')) hide();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });
    window.addEventListener('scroll', hide, { passive: true });

    return { show, hide, el: host };
}
