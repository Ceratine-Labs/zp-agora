import test from 'node:test';
import assert from 'node:assert/strict';
import {
    wallPreferences, wallOrder, wallItems, wallOverview, wallSnapshotUrl, wallNextPage, wallResponsePolicy,
} from '../../resources/js/components/operations-wall.js';

const sites = [{ id: 2, name: 'Site two' }, { id: 7, name: 'Site seven' }];
const screens = ['overview', 'fuel', 'shop', 'qsr', 'stock', 'cashups'].map((id) => ({ id }));

test('overview includes permitted site work with its open count and oldest date', () => {
    const overview = wallOverview({
        screens: [], generatedAt: '2026-11-05T08:00:00Z', scope: { siteCount: 1 }, siteWorkAvailable: true,
        siteWork: [{ id: 'queue-3-day-close', site: 'Site A', title: 'Days open', severity: 'warn',
            value: '2', detail: 'Open: 2 · Oldest: 01 Oct 2026 · Last 90 days' }],
    });
    assert.equal(overview.status, 'live');
    assert.equal(overview.items[0].value, '2');
    assert.match(overview.items[0].detail, /Oldest: 01 Oct 2026/);
});

test('saved preferences accept only available sites and supported settings', () => {
    const value = wallPreferences({
        branches: [2, '2', '999', '<script>', 7],
        screens: ['fuel', '<script>', 'cashups'], seconds: 0, autoReturn: false,
    }, sites);
    assert.deepEqual(value.branches, ['2', '7']);
    assert.deepEqual(value.screens, ['fuel', 'cashups']);
    assert.equal(value.seconds, 25);
    assert.equal(value.autoReturn, false);
    assert.equal(value.scopeInvalid, false);
    assert.equal(value.hideZero, true);
    assert.equal(wallPreferences({ hideZero: true }, sites).hideZero, true);
    assert.equal(wallPreferences({}, sites).hideZero, true);
    assert.equal(wallPreferences({ hideZero: false }, sites).hideZero, false);
    assert.equal(wallPreferences({ hideZero: 'false' }, sites).hideZero, false);
});

test('expired saved sites block fetch instead of widening to all permitted sites', () => {
    const stale = wallPreferences({ branches: ['999'] }, sites);
    assert.deepEqual(stale.branches, []);
    assert.equal(stale.scopeInvalid, true);
    assert.throws(() => wallSnapshotUrl('/app/operations/snapshot', stale, 'https://agora.test/app/operations'), /Choose sites/);
    // Saving a display option while blocked must not erase the restriction.
    assert.equal(wallPreferences(JSON.parse(JSON.stringify(stale)), sites).scopeInvalid, true);
});

test('an explicit empty selection means all permitted sites and can repair a stale preference', () => {
    const all = wallPreferences({ branches: [], scopeInvalid: false }, sites);
    assert.equal(all.scopeInvalid, false);
    const url = wallSnapshotUrl('/app/operations/snapshot?branches[]=999&division=old', all, 'https://agora.test/app/operations');
    assert.equal(url.searchParams.has('branches[]'), false);
    assert.equal(url.searchParams.has('division'), false);
});

test('snapshot URLs carry only the selected scope to the same origin', () => {
    const selected = wallPreferences({ branches: [7, 2] }, sites);
    const url = wallSnapshotUrl('/app/operations/snapshot?branches[]=999', selected, 'https://agora.test/app/operations');
    assert.deepEqual(url.searchParams.getAll('branches[]'), ['7', '2']);
    assert.throws(() => wallSnapshotUrl('https://elsewhere.test/snapshot', selected, 'https://agora.test/app/operations'), /belong to Agora/);
});

test('cash-ups follow overview before noon SAST and return to ordinary order at noon', () => {
    const preferences = wallPreferences({}, sites);
    assert.deepEqual(wallOrder(screens, preferences, new Date('2026-10-20T09:59:59Z')), ['overview', 'cashups', 'fuel', 'shop', 'qsr', 'stock']);
    assert.deepEqual(wallOrder(screens, preferences, new Date('2026-10-20T10:00:00Z')), ['overview', 'fuel', 'shop', 'qsr', 'stock', 'cashups']);
    assert.deepEqual(wallOrder(screens, { ...preferences, morning: false }, new Date('2026-10-20T06:00:00Z')), ['overview', 'fuel', 'shop', 'qsr', 'stock', 'cashups']);
});

test('rotation honours chosen and available screens and never becomes empty', () => {
    const preferences = wallPreferences({ screens: ['cashups', 'fuel'] }, sites);
    assert.deepEqual(wallOrder(screens.filter((screen) => screen.id !== 'cashups'), preferences), ['fuel']);
    assert.deepEqual(wallOrder([{ id: 'overview' }], preferences), ['overview']);
    assert.deepEqual(wallPreferences({ screens: [] }, sites).screens, ['overview']);
});

test('critical signals lead with stable ordering and attention filtering does not mutate the source', () => {
    const items = [
        { id: 'good', severity: 'good' }, { id: 'warn', severity: 'warn' },
        { id: 'critical-1', severity: 'crit' }, { id: 'unknown', severity: 'unexpected' },
        { id: 'critical-2', severity: 'crit' },
    ];
    assert.deepEqual(wallItems({ items }).map((item) => item.id), ['critical-1', 'critical-2', 'warn', 'unknown', 'good']);
    assert.deepEqual(wallItems({ items }, true).map((item) => item.id), ['critical-1', 'critical-2', 'warn']);
    assert.equal(items[0].id, 'good');
});

test('overview counts only delivered screens and retains unavailable coverage instead of green zeros', () => {
    const overview = wallOverview({
        generatedAt: '2026-10-20T06:00:00Z', scope: { siteCount: 2 },
        screens: [
            { id: 'fuel', title: 'Fuel', status: 'live', sourceLabel: 'Tank readings', items: [{ id: '1', severity: 'crit' }], gaps: [] },
            { id: 'shop', title: 'Shop', status: 'unavailable', message: 'No mapped feed', items: [], gaps: ['KVI list not configured'] },
        ],
    });
    assert.equal(overview.metrics.find((metric) => metric.label === 'Areas reporting').value, '1 / 2');
    assert.equal(overview.metrics.find((metric) => metric.label === 'Areas reporting').tone, 'warn');
    assert.equal(overview.metrics.find((metric) => metric.label === 'Critical signals').value, '1');
    assert.equal(overview.items[0].id, 'fuel:1');
    assert.deepEqual(overview.gaps, ['Shop: No mapped feed', 'Shop: KVI list not configured']);
});

test('hide zero values composes with attention and keeps missing and nonzero signals', () => {
    const items = [
        { id: 'zero-stock', severity: 'crit', value: '0.000', isZero: true },
        { id: 'zero-cash', severity: 'good', value: 'R 0.00', isZero: true },
        { id: 'negative', severity: 'crit', value: '-1.000', isZero: false },
        { id: 'missing', severity: 'neutral', value: '—', isZero: false },
        { id: 'rounded', severity: 'warn', value: '0.0 L', isZero: false },
    ];
    assert.equal(wallItems({ items }).length, 5);
    assert.deepEqual(wallItems({ items }, false, true).map((item) => item.id), ['negative', 'rounded', 'missing']);
    assert.deepEqual(wallItems({ items }, true, true).map((item) => item.id), ['negative', 'rounded']);
    assert.deepEqual(wallItems({ items: items.slice(0, 2) }, false, true), []);
    const saved = wallPreferences(JSON.parse(JSON.stringify(wallPreferences({ hideZero: true }, sites))), sites);
    assert.equal(saved.hideZero, true);
    const overview = wallOverview({ screens: [{ id: 'shop', title: 'Shop', status: 'live', items, gaps: [] }], scope: { siteCount: 1 } });
    assert.equal(wallItems(overview, false, true).length, 3);
});

test('a fully unavailable wall remains unavailable and does not claim a good result', () => {
    const overview = wallOverview({
        generatedAt: '2026-10-20T06:00:00Z', scope: { siteCount: 2 },
        screens: [{ id: 'fuel', title: 'Fuel', status: 'error', items: [], gaps: [] }],
    });
    assert.equal(overview.status, 'unavailable');
    assert.equal(overview.metrics.some((metric) => metric.tone === 'good'), false);
    assert.equal(overview.gaps[0], 'Fuel: Source unavailable');
});

test('unattended revisits advance through every group and wrap only after the final group', () => {
    let page = 0;
    const visited = [];
    for (let visit = 0; visit < 5; visit++) {
        visited.push(page);
        page = wallNextPage(page, 19);
    }
    assert.deepEqual(visited, [0, 1, 2, 3, 0]);
    assert.equal(wallNextPage(0, 0), 0);
    assert.equal(wallNextPage(0, 6), 0);
    assert.equal(wallNextPage(0, 7), 1);
});

test('an access refusal clears figures and stops retries while a transient failure keeps the snapshot', () => {
    for (const status of [401, 403, 419]) {
        assert.deepEqual(wallResponsePolicy(status), { accessChanged: true, keepSnapshot: false, retry: false });
    }
    assert.deepEqual(wallResponsePolicy(200, true), { accessChanged: true, keepSnapshot: false, retry: false });
    for (const status of [200, 429, 500, 503]) {
        assert.deepEqual(wallResponsePolicy(status), { accessChanged: false, keepSnapshot: true, retry: true });
    }
});
