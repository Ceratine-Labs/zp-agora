import test from 'node:test';
import assert from 'node:assert/strict';
import { previewSites } from '../../resources/js/components/stock-recon-group.js';

test('sites run sequentially and a failed site does not prevent the next result', async () => {
    const events = [];
    let active = 0;
    await previewSites([7, 9, 18], async (site) => {
        assert.equal(active++, 0);
        events.push(`start:${site}`);
        await Promise.resolve();
        active--;
        if (site === 9) throw new Error('source failed');
        return { status: 'previewed' };
    }, (site, result, error) => events.push(`${site}:${error ? 'failed' : result.status}`));
    assert.deepEqual(events, ['start:7', '7:previewed', 'start:9', '9:failed', 'start:18', '18:previewed']);
});
