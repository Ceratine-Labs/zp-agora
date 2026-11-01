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
import info from './components/info';
import runbar from './components/runbar';
import tip from './components/tip';
import dataGrid from './components/data-grid';
import operationsWall from './components/operations-wall';
import reconGroup from './components/recon-group';
import stockReconGroup from './components/stock-recon-group';
import stockReconTutorial from './components/stock-recon-tutorial';
import reconMatch from './components/recon-match';
import reconSuggest from './components/recon-suggest';
import modal from './components/modal';
import tableTools from './components/table-tools';
import linkedSelect from './components/linked-select';
import dateSpan from './components/date-span';
import bulkSelection from './components/bulk-selection';
import postLink from './components/post-link';
import duplicateRelease from './components/duplicate-release';
import loader, { afterPaint } from './components/loader';
import * as format from './format';

// Install the shared API before any initializer can start a request or throw.
// The head's startup guard survives the bundle taking ownership of readiness.
window.Agora = { ...window.Agora, notify, format, tip: null, loader: loader() };
const failures = [];

function initialize(name, setup) {
    try {
        return Promise.resolve(setup()).catch((error) => {
            failures.push({ name, error });
            console.error(`${name} could not initialize.`, error);
            throw error;
        });
    } catch (error) {
        failures.push({ name, error });
        console.error(`${name} could not initialize.`, error);
        return Promise.reject(error);
    }
}

// A lazy pane can arrive during startup. Its controls participate in the
// pane's promise; later hydrations use the same wiring without reopening the
// page loader. Each component limits itself to this fragment and marks its work.
window.Agora.hydrate = (root) => Promise.allSettled([
    initialize('Fragment tutorial', () => stockReconTutorial(root)),
    initialize('Fragment selects', () => selects(root)),
    initialize('Fragment charts', () => charts(root)),
    initialize('Fragment row details', () => rowDetail(root)),
    initialize('Fragment selection', () => checkAll(root)),
    initialize('Fragment tabs', () => tabs(root)),
    initialize('Fragment disclosures', () => disclosure(root)),
    initialize('Fragment information', () => info(root)),
    initialize('Fragment actions', () => runbar(root)),
    initialize('Fragment reconciliation', () => reconMatch(root)),
    initialize('Fragment table tools', () => tableTools(root)),
    initialize('Fragment confirmations', () => confirmForm(root)),
    initialize('Fragment suggestions', () => reconSuggest(root)),
    initialize('Fragment data grids', () => dataGrid(root)),
]);

// Give the already-rendered loader a paint before synchronous table setup can
// occupy the main thread. No component fetches anything when it has no markup.
const initial = afterPaint().then(() => Promise.allSettled([
    initialize('Navigation', megaMenu),
    initialize('Theme', themeToggle),
    initialize('Selects', selects),
    initialize('Charts', charts),
    initialize('Row details', rowDetail),
    initialize('Selection', checkAll),
    initialize('Tabs', tabs),
    initialize('Disclosures', disclosure),
    initialize('Information', info),
    initialize('Actions', runbar),
    // These mount progress controls; their existing long-running batch jobs
    // remain visible background operations rather than page setup promises.
    initialize('Bank reconciliation group', reconGroup),
    initialize('Stock reconciliation group', stockReconGroup),
    initialize('Stock tutorial', stockReconTutorial),
    initialize('Reconciliation matching', reconMatch),
    initialize('Modals', modal),
    initialize('Table tools', tableTools),
    initialize('Linked selects', linkedSelect),
    initialize('Date ranges', dateSpan),
    initialize('Bulk actions', bulkSelection),
    initialize('Help tips', () => { window.Agora.tip = tip(); }),
    initialize('Confirmations', confirmForm),
    initialize('Post links', postLink),
    initialize('Duplicate release', duplicateRelease),
    initialize('Reconciliation suggestions', reconSuggest),
    initialize('Data grids', dataGrid),
    initialize('Operations wall', operationsWall),
])).then(async (results) => {
    // Late fragments and charts can introduce fonts after the first layout.
    const fonts = await Promise.allSettled([initialize('Fonts', () => document.fonts?.ready)]);
    return { results: [...results, ...fonts], failures };
});

// All asynchronous imports, chart draws, first live snapshot and initial lazy
// panes finish before reveal. Failure leaves the server's usable page visible.
window.Agora.ready = window.Agora.loader.ready(initial);
