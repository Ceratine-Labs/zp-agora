/**
 * Light / dark toggle.
 *
 * Three states, not two: no stamp means "follow the system", which is what
 * most people are on. The toggle reads what is actually being rendered before
 * deciding what to switch to, so the first click always visibly changes
 * something — computing it from the stamp alone makes the first click a no-op
 * for anyone on the system default.
 *
 * The choice is stored in a cookie rather than localStorage so the server can
 * stamp <html> on the way out and the page never flashes the wrong theme.
 */
export default function themeToggle() {
    const button = document.getElementById('themebtn');
    if (!button) return;

    button.addEventListener('click', () => {
        const stamped = document.documentElement.getAttribute('data-theme');
        const dark = stamped
            ? stamped === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;

        const next = dark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        document.cookie = `agora_theme=${next}; path=/; max-age=31536000; samesite=lax`;
    });
}
