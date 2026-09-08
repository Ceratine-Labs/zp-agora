import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await b.newPage();
const errs = [], reqs = [];
p.on('pageerror', e => errs.push('PAGEERROR ' + String(e)));
p.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text()); });
p.on('request', r => { if (r.method() === 'POST' || r.url().includes('/branches/')) reqs.push(r.method() + ' ' + r.url()); });
// The page's own POSTs must not actually hit live.
await p.route('**/branches/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<tr data-group-branch="x"><td></td><td>ok</td><td>done</td><td>1</td><td>1</td><td>R1</td><td>0</td><td>0</td><td>—</td></tr>' }));
await p.goto('' + process.argv[2], { waitUntil: 'networkidle' });
const state = await p.evaluate(() => {
  const panel = document.querySelector('[data-recon-group]');
  const btn = panel?.querySelector('[data-group-start]');
  return {
    panel: !!panel,
    button: !!btn,
    branches: (panel?.dataset.groupBranches || '').split(',').filter(Boolean).length,
    url: panel?.dataset.groupUrl,
    rowsWithBranch: document.querySelectorAll('tr[data-group-branch]').length,
    agora: typeof window.Agora,
  };
});
console.log('STATE', JSON.stringify(state));
await p.click('[data-group-start]').catch(e => errs.push('CLICK ' + e.message));
await p.waitForTimeout(2500);
console.log('button now:', await p.textContent('[data-group-start]').catch(() => '(gone)'));
console.log('status now:', await p.textContent('[data-group-status]').catch(() => '(gone)'));
console.log('POSTs attempted:', reqs.length, reqs.slice(0, 2));
console.log('errors:', errs.length ? errs.slice(0, 4) : 'none');
await b.close();
