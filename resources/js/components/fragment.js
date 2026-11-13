/**
 * A part of a page that is fetched after the page shows.
 *
 *     <div data-fragment="/app/stock-recon/days?month=2026-11">Reading…</div>
 *
 * For a reading that costs a second or two (the stock recon day calendar
 * across every site) on a page people open for something else: the page
 * paints at once, the fragment fills in when it arrives. Deliberately NOT
 * part of the page's ready promise — waiting for it is exactly what this
 * avoids.
 *
 * A link inside marked `data-fragment-link` (the calendar's month arrows)
 * replaces the fragment from its own href instead of leaving the page.
 *
 * A redirected answer is the session ending (the login page, answering 200)
 * and is never pasted in as if it were the fragment.
 */
export default function fragment(root = document) {
    root.querySelectorAll('[data-fragment]:not([data-fragment-ready])').forEach((el) => {
        el.setAttribute('data-fragment-ready', '');

        el.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-fragment-link]');
            if (! link || ! el.contains(link) || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            load(el, link.href);
        });

        load(el, el.dataset.fragment);
    });
}

async function load(el, url) {
    el.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        });
        if (response.redirected) throw new Error('your session has ended — reload the page to sign in again');
        if (! response.ok) throw new Error(`the server answered ${response.status}`);

        el.innerHTML = await response.text();
        el.dataset.fragment = url;
        await window.Agora?.hydrate?.(el);
    } catch (error) {
        const p = document.createElement('p');
        p.className = 'field-help';
        p.textContent = `This could not be read — ${error.message || String(error)}.`;
        el.replaceChildren(p);
    } finally {
        el.removeAttribute('aria-busy');
    }
}
