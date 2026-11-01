/** Sequential, resumable previews. The server persists results and prevents duplicate runs. */
export async function previewSites(sites, send, update) {
    for (const site of sites) {
        try {
            update(site, await send(site), null);
        } catch (error) {
            update(site, null, error);
        }
    }
}

export default function stockReconGroup() {
    const panel = document.querySelector('[data-stock-group]');
    if (!panel) return;
    let running = false;
    const status = panel.querySelector('[data-stock-group-status]');
    const start = panel.querySelector('[data-stock-group-start]');
    const retry = panel.querySelector('[data-stock-group-retry]');
    const run = async (retryFailed) => {
        if (running) return;
        running = true;
        panel.querySelectorAll('button').forEach((button) => { button.disabled = true; });
        const sites = [...panel.querySelectorAll('[data-stock-group-site]')]
            .filter((site) => site.dataset.state === (retryFailed ? 'failed' : 'pending'));
        let done = 0;
        await previewSites(sites, async (site) => {
            status.textContent = `Previewing ${site.dataset.name} (${done + 1} of ${sites.length})…`;
            const response = await fetch(site.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ retry: retryFailed }),
            });
            if (!response.ok) throw new Error(`Preview request failed (${response.status}). Reload to resume this site.`);
            return response.json();
        }, (site, result, error) => {
            done += 1;
            site.querySelector('[data-stock-group-result]').textContent = error?.message ?? result.message ?? 'Preview ready for review.';
            if (result) site.dataset.state = result.status;
        });
        // Persisted server results are authoritative, including a request whose
        // response was lost after it succeeded. Refresh never auto-runs a retry.
        window.location.reload();
    };
    start?.addEventListener('click', () => run(false));
    retry?.addEventListener('click', () => run(true));
    if (panel.dataset.auto === '1' && start) run(false);
}
