{{-- First-paint protection lives ahead of the external assets: a slow bundle
     must not expose a partially initialized page. With JavaScript disabled
     the marker is never set, so the ordinary server-rendered view stays usable. --}}
<style>
    html:not([data-app-starting]) .loader-overlay[data-loader-startup] { display: none; }
    html[data-app-starting] { overflow: hidden; }
    html[data-app-starting] body > :not(.loader-overlay):not(script):not(style) { visibility: hidden; }
    html[data-app-starting] .loader-overlay[data-loader-startup] {
        position: fixed; inset: 0; z-index: 2000; display: flex;
        align-items: center; justify-content: center;
        background: var(--paper, Canvas); color: var(--ink, CanvasText);
        visibility: visible; opacity: 1; animation: none; backdrop-filter: none;
    }
    html[data-app-starting] .loader-overlay[data-loader-startup] .loader-box { text-align: center; }
    html[data-app-starting] .loader-overlay[data-loader-startup] .loader-art { width: 112px; height: 112px; }
</style>
<script>
    (() => {
        const root = document.documentElement;
        root.setAttribute('data-app-starting', '');
        let timer;
        const finish = () => {
            root.removeAttribute('data-app-starting');
            window.clearTimeout(timer);
            window.removeEventListener('error', failedAsset, true);
        };
        const fallback = () => {
            if (!root.hasAttribute('data-app-starting')) return;
            finish();
            const overlay = document.querySelector('.loader-overlay[data-loader-startup]');
            if (overlay) {
                overlay.removeAttribute('data-loader-startup');
                overlay.removeAttribute('data-loader-hold');
                overlay.hidden = true;
            }
        };
        const failedAsset = (event) => {
            if (event.target instanceof HTMLScriptElement && event.target.type === 'module') fallback();
        };
        // If the entry bundle cannot run, release the usable HTML. The timer
        // also covers a stalled connection without affecting later actions.
        timer = window.setTimeout(fallback, 60000);
        window.addEventListener('error', failedAsset, true);
        window.addEventListener('pageshow', (event) => { if (event.persisted) fallback(); });
        window.Agora = { startup: { finish } };
    })();
</script>
