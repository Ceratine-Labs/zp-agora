/**
 * "Explain this chain" (Ryan, 15 Nov 2026): the chain panel's button posts to
 * the explain route and puts the answer under it. Delegated from the
 * document, because the chain panel arrives after load.
 *
 *   <div data-chain-explain="/app/stock-recon/runs/1/lines/2/explain">
 *     <button type="button" data-chain-explain-go>Explain this chain</button>
 *     <div class="chain-explain-body" hidden></div>
 *   </div>
 */
export default function chainExplain() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-chain-explain-go]');
        if (!button) return;

        const box = button.closest('[data-chain-explain]');
        const body = box?.querySelector('.chain-explain-body');
        if (!box || !body || button.disabled) return;

        event.preventDefault();
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Reading the chain… (up to a minute)';

        try {
            const response = await fetch(box.dataset.chainExplain, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    Accept: 'text/html',
                },
            });
            if (response.status === 429) throw new Error('Too many explanations at once. Wait a minute and try again.');
            if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);

            body.innerHTML = await response.text();
            body.hidden = false;
            button.hidden = true;
        } catch (error) {
            body.textContent = `The explanation could not be read: ${error.message}`;
            body.hidden = false;
            button.textContent = label;
        } finally {
            button.disabled = false;
        }
    });
}
