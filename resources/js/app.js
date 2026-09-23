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
import dataGrid from './components/data-grid';
import reconGroup from './components/recon-group';
import reconMatch from './components/recon-match';
import reconSuggest from './components/recon-suggest';
import modal from './components/modal';
import tableTools from './components/table-tools';
import linkedSelect from './components/linked-select';
import bulkSelection from './components/bulk-selection';
import postLink from './components/post-link';
import loader from './components/loader';
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
// Does nothing on a page with no group in flight, which is every page but one.
reconGroup();
reconMatch();
modal();
// Sorting, column filters and the sticky heads. Every table gets the head
// offset; only <x-table tools> gets the controls.
tableTools();
// A select whose options belong to another select's choice — the counting
// areas at a site. Does nothing on a page with none, which is every page but
// the stock recon centre.
linkedSelect();
// Copies a grid's ticked rows into the batch form that acts on them. Does
// nothing on a page with no `data-bulk-form`, which is every page but the
// stock recon master.
bulkSelection();

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
// `loader` is the coffee cup (<x-loader>): the one place that decides when a
// wait is long enough to be shown. See loader.js.
window.Agora = { notify, format, tip: tip(), loader: loader() };

// After the global exists — it asks through window.Agora.notify, so that the
// dialog is the same one every other confirmation in the application uses.
confirmForm();

// Same reason: a row action that POSTs asks in the same dialog as a form does.
postLink();

// Same reason: "match all strong" on the recon Suggestions tab asks in the
// same dialog before it posts each one. Nothing on any other page.
reconSuggest();

// Same reason: the column chooser's reset asks before it forgets a layout, and
// the drawer's copy button reports through the shared toast.
dataGrid();

/*
 * Wiring for markup that arrives AFTER load.
 *
 * Everything above ran once, over the page the server sent. A recon centre
 * tab is a fragment fetched when it is opened (tabs.js), and its tables, row
 * details, tick boxes, confirmations and bulk presses need exactly the same
 * wiring — so each component that can appear in one takes a root, marks what
 * it has wired, and is run again over just the new markup. The order is the
 * order above, for the same reasons: the action bar before the table tools,
 * the confirmations after window.Agora exists.
 */
window.Agora.hydrate = (root) => {
    selects(root);
    rowDetail(root);
    checkAll(root);
    tabs(root);
    disclosure(root);
    runbar(root);
    reconMatch(root);
    tableTools(root);
    confirmForm(root);
    reconSuggest(root);
};
