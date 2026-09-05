import megaMenu from './components/mega-menu';
import themeToggle from './components/theme';
import selects from './components/select';
import charts from './components/chart';
import notify from './components/notify';
import rowDetail from './components/row-detail';
import confirmForm from './components/confirm-form';
import checkAll from './components/check-all';
import tabs from './components/tabs';
import disclosure from './components/disclosure';
import runbar from './components/runbar';
import tip from './components/tip';
import * as format from './format';

megaMenu();
themeToggle();

// Every one of these looks for its own elements and does nothing — and fetches
// nothing — when the page has none. Most pages have none.
selects();
charts();
rowDetail();
checkAll();
tabs();
disclosure();
runbar();

// Reachable from an inline handler in a blade view without importing anything
// there. This is the only global the application defines.
//
// `tip` returns null on a page with no <x-tip> mounted, which is most of them;
// a caller that wants it must check, the same way it would check for any
// element that may not be there.
//
// `format` is here rather than imported per page because
// `Vite::asset('resources/js/format.js')` needs format.js to be its own build
// input, and it is not one — so that call threw "Unable to locate file in Vite
// manifest" against built assets and took the whole page down. The parity
// table on /dev/components is the only guard on the PHP and JS formatters
// agreeing, so it was the guard that was broken. Reading the twin off the
// shipped bundle is also the stronger test: it checks the module the
// application actually runs, not a second copy of it built for one page.
window.Agora = { notify, format, tip: tip() };

// After the global exists — it asks through window.Agora.notify, so that the
// dialog is the same one every other confirmation in the application uses.
confirmForm();
