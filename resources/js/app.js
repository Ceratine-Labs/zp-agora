import megaMenu from './components/mega-menu';
import themeToggle from './components/theme';
import selects from './components/select';
import charts from './components/chart';
import notify from './components/notify';
import rowDetail from './components/row-detail';
import confirmForm from './components/confirm-form';
import checkAll from './components/check-all';
import dataGrid from './components/data-grid';

megaMenu();
themeToggle();

// Both look for their own elements and do nothing — and fetch nothing — when
// the page has none. Most pages have none.
selects();
charts();
rowDetail();
checkAll();

// Reachable from an inline handler in a blade view without importing anything
// there. This is the only global the application defines.
window.Agora = { notify };

// After the global exists — it asks through window.Agora.notify, so that the
// dialog is the same one every other confirmation in the application uses.
confirmForm();

// Same reason: the column chooser's reset asks before it forgets a layout, and
// the drawer's copy button reports through the shared toast.
dataGrid();
