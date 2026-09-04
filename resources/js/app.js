import megaMenu from './components/mega-menu';
import themeToggle from './components/theme';
import selects from './components/select';
import charts from './components/chart';
import notify from './components/notify';

megaMenu();
themeToggle();

// Both look for their own elements and do nothing — and fetch nothing — when
// the page has none. Most pages have none.
selects();
charts();

// Reachable from an inline handler in a blade view without importing anything
// there. This is the only global the application defines.
window.Agora = { notify };
