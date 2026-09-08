<?php

namespace Modules\Core\Support;

/**
 * What the component library contains, declared once.
 *
 * Two things read this and they must not be allowed to disagree: the gallery
 * at /dev/components, which renders every component beside its props, and
 * `php artisan agora:components-doc`, which writes the table in
 * docs/components.md. Before this existed the doc was maintained by hand, and
 * a doc maintained by hand next to a gallery maintained by hand is two
 * inventories that drift — which is exactly the failure the doc exists to
 * prevent, since its whole job is to stop the next session building a second
 * version of something.
 *
 * It is a declaration, not a reflection: Blade's `@props` are not readable
 * without compiling the view, and a props table derived from a compiled
 * template would say what the defaults are without ever saying what they mean.
 * The `what` column is the part worth having.
 *
 * `scripts/check-components.sh` runs the doc generator in --check mode, so a
 * component added here without the doc being regenerated fails the gate.
 */
class ComponentCatalogue
{
    /** The order groups appear in, in the gallery and in the doc. */
    public const GROUPS = [
        'Chrome' => 'The frame around a screen. Fed by ShellComposer, so a page using the shell needs no navigation data of its own.',
        'Structure' => 'How a screen is divided: panels, figures, tabs.',
        'Data' => 'Rendering an answer — and rendering the absence of one.',
        'Parameters' => 'Asking for the arguments, and running the thing.',
        'Queues' => 'Work waiting on a person: exceptions, decisions, steps, proposals.',
        'Messages' => 'Something the reader has to take in before the figures mean what they look like.',
        'Module-scoped' => 'Lives in a module rather than the shared library, and is used as <x-{alias}::name>.',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * Everything that exists, with its props.
     *
     * Memoised because the gallery asks for it once per entry — a static array
     * rebuilt thirty times is cheap, but it is also pointless.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $components = [
            [
                'name' => 'x-app-shell',
                'group' => 'Chrome',
                'summary' => 'The whole page: head, app bar, scope bar, main, footer.',
                'mockup' => 'the document',
                'notes' => 'Fed by `ShellComposer`, so a page using it needs no nav data of its own.',
                'gallery' => false,
                'props' => [
                    ['title', 'string', "'Agora'", 'Browser title, suffixed with · Agora'],
                    ['slot', 'slot', '—', 'The page'],
                ],
            ],
            [
                'name' => 'x-app-bar',
                'group' => 'Chrome',
                'summary' => 'Brand, workspace switch, section buttons, tools.',
                'mockup' => 'appbar, mega',
                'notes' => 'Renders one `.mega` panel per section.',
                'gallery' => false,
                'props' => [
                    ['sections', 'Collection', 'collect()', 'MenuSection rows, each with its item tree'],
                    ['workspace', 'string', "'ho'", 'Which workspace is current'],
                    ['workspaces', 'array', '[]', 'code => label'],
                ],
            ],
            [
                'name' => 'x-menu-branch',
                'group' => 'Chrome',
                'summary' => 'One menu node, drawn at whatever depth it sits.',
                'mockup' => 'mega',
                'notes' => '**Recursive.** Depth 1 = column heading, 2 = link, 3+ = a `<details>` group that expands in place. No ceiling on depth.',
                'gallery' => false,
                'props' => [
                    ['item', 'MenuItem', '—', 'The node'],
                    ['depth', 'int', '1', 'Where it sits in the tree'],
                ],
            ],
            [
                'name' => 'x-scope-bar',
                'group' => 'Chrome',
                'summary' => 'Branch and "as at" selector, submitted into the URL.',
                'mockup' => 'ctx',
                'notes' => 'Scope is a parameter, not a report — the URL carries it so a link travels. '
                    .'`branches` arrives already narrowed to the person\'s grants in `agora.UserBranch`: '
                    .'`ShellComposer` builds it through `Branch::visibleTo()`, the same scope '
                    .'`ResolveBranchContext` picks the pinned site with, so what the bar OFFERS and what a '
                    .'query RETURNS cannot drift apart. `granted` says whether that narrowing happened, so '
                    .'the note can read "2 sites granted to you" rather than a count with no provenance.',
                'gallery' => false,
                'props' => [
                    ['branches', 'Collection', 'collect()', 'Sites this user may see'],
                    ['branchId', '?int', 'null', 'The one in scope'],
                    ['workspace', 'string', "'ho'", 'Hides "All sites" in the branch workspace'],
                    ['granted', 'bool', 'false', 'True when the list is the user\'s own grants rather than the whole trading estate'],
                ],
            ],
            [
                'name' => 'x-workspace-switch',
                'group' => 'Chrome',
                'summary' => 'Head office / Branch, as its own component.',
                'mockup' => 'wsw',
                'notes' => 'Anchors, not buttons: switching workspace changes what the application is about, so it belongs in the URL and the back button should undo it. `fullUrlWithQuery` keeps the branch and date already in scope.',
                'props' => [
                    ['workspaces', 'array', '[]', 'code => label'],
                    ['current', '?string', 'null', 'The code that is on'],
                    ['param', 'string', "'ws'", 'Query key it writes'],
                    ['label', 'string', "'Workspace'", 'Accessible name for the group'],
                ],
            ],
            [
                'name' => 'x-crumb',
                'group' => 'Chrome',
                'summary' => 'Where you are.',
                'mockup' => 'crumb',
                'notes' => 'A real `<nav>` over an `<ol>`, so a screen reader says "breadcrumb, 3 items" instead of reading the separators. The last part is the current page and carries no link.',
                'props' => [
                    ['parts', 'array', '[]', "List of ['label' =>, 'href' =>], or plain strings"],
                ],
            ],
            [
                'name' => 'x-page-head',
                'group' => 'Chrome',
                'summary' => 'Page title block.',
                'mockup' => 'page-head',
                'notes' => '',
                'props' => [
                    ['eyebrow', '?string', 'null', 'Small uppercase label above the title'],
                    ['title', 'string', "''", 'The heading'],
                    ['blurb', '?string', 'null', 'One sentence under it'],
                    ['actions', 'slot', '—', 'Buttons, pushed right'],
                ],
            ],
            [
                'name' => 'x-card',
                'group' => 'Structure',
                'summary' => 'Bordered panel.',
                'mockup' => 'card / card-h / card-b / flush',
                'notes' => '`flush` removes body padding, for a table that goes edge to edge. `collapsible` makes the head a `<details>` toggle — the browser supplies the keyboard behaviour and it works with no JS; `open` says which way it starts, and `remember` keeps that choice on this browser. A card carrying provenance rather than the answer starts closed. The older `card-head` / `card-body` class names are still styled.',
                'props' => [
                    ['title', '?string', 'null', 'Head line; without one there is no head'],
                    ['sub', '?string', 'null', 'Second line in the head'],
                    ['flush', 'bool', 'false', 'Body padding off'],
                    ['collapsible', 'bool', 'false', 'Head becomes a <details> toggle'],
                    ['open', 'bool', 'false', 'Which way a collapsible card starts'],
                    ['remember', '?string', 'null', 'disclosure.js key for the open state'],
                    ['actions', 'slot', '—', 'Controls in the head, pushed right'],
                    ['foot', 'slot', '—', 'A runbar or toolbar under the body'],
                ],
            ],
            [
                'name' => 'x-kpi-strip',
                'group' => 'Structure',
                'summary' => 'The row of KPI tiles at the top of a screen.',
                'mockup' => 'kpis',
                'notes' => 'A grid, so four tiles across a desk become two and then one on a phone without anything being hidden.',
                'props' => [
                    ['slot', 'slot', '—', 'The tiles'],
                ],
            ],
            [
                'name' => 'x-kpi',
                'group' => 'Structure',
                'summary' => 'One figure, on a tile.',
                'mockup' => 'kpi / lbl / val / cmp / stripe / spark',
                'notes' => '`value` has already been through `App\Support\Format` — the component does not format, because only the caller knows whether the figure is money, litres or a count. `stripe` names a design token and is checked against a list, so an unknown value is dropped rather than interpolated into a style attribute. `href` makes the whole tile a link.',
                'props' => [
                    ['label', 'string', "''", 'The uppercase label'],
                    ['value', 'string', "''", 'The figure, already formatted'],
                    ['unit', '?string', 'null', 'Small suffix inside the figure'],
                    ['compare', 'string|array|null', 'null', 'The comparison line(s) under it'],
                    ['note', '?string', 'null', 'Older single-line spelling of compare'],
                    ['tone', 'string', "'neutral'", 'neutral good warn serious crit'],
                    ['stripe', '?string', 'null', 'Token name: brand good warn serious crit s1–s5'],
                    ['href', '?string', 'null', 'Makes the tile a link'],
                    ['spark', 'slot', '—', 'Where <x-sparkline> goes'],
                ],
            ],
            [
                'name' => 'x-statstrip',
                'group' => 'Structure',
                'summary' => 'A row of figures describing the result set below it.',
                'mockup' => 'statstrip / s / l / v / n',
                'notes' => 'Not `<x-kpi>`: a KPI is a headline about the business, a stat is about this answer, and it lives inside the card it describes. The longer `.stat / .stat-label / .stat-value` class names are still styled, so views written against them render unchanged.',
                'props' => [
                    ['stats', 'array', '[]', "List of ['label','value','note','tone']; values already formatted"],
                ],
            ],
            [
                'name' => 'x-tabs',
                'group' => 'Structure',
                'summary' => 'A strip of tabs, in link mode or panel mode.',
                'mockup' => 'tabs',
                'notes' => 'Give the items an `href` and it renders anchors and uses no JavaScript at all — the right mode whenever the panes are separate result sets, because a link carries its scope. Leave `href` out, put `<x-tab-panel>` children in the slot, and `tabs.js` adds the two things the platform does not give: memory of the last choice (`persist`) and arrow-key movement across the strip.',
                'js' => 'tabs.js',
                'props' => [
                    ['items', 'array', '[]', "['key','label','href','count'] each, or plain strings"],
                    ['active', '?string', 'first key', 'Which one is on. **Required in panel mode**'],
                    ['persist', '?string', 'null', 'localStorage key for the last choice'],
                    ['label', 'string', "'Sections'", 'Accessible name for the strip'],
                ],
            ],
            [
                'name' => 'x-tab-panel',
                'group' => 'Structure',
                'summary' => 'One pane behind a tab.',
                'mockup' => 'tabs',
                'notes' => 'Takes `active` off the parent `<x-tabs>` with `@aware`, so a page lists its panes without repeating the selection. The inactive ones carry `hidden` from the server — that is what makes the first paint correct with no JavaScript.',
                'props' => [
                    ['key', 'string', '—', 'Matches an item key on the parent'],
                ],
            ],
            [
                'name' => 'x-data-grid',
                'group' => 'Data',
                'summary' => 'Every user-facing result set: filters, export, column persistence, links and row actions.',
                'mockup' => 'dg',
                'notes' => 'Feature-rules §3 in one component, and a screen gets all of it by passing `$grid`. '
                    .'**Everything it draws is declared on the GridDefinition, never in the screen.** '
                    .'A column with `link: true` is rendered as an anchor to `rowUrl($row)` — that is §3.7 for the row\'s OWN resource, and the two together are what makes a list openable. '
                    .'`rowActions($row)` adds a trailing column of buttons, and an action the caller may not perform is simply absent from the array, which is §4\'s "a control the user cannot use is not rendered". '
                    .'A value naming some OTHER record stays in the grid\'s own `_cells` partial, because only the screen knows which values do that. '
                    .'Both the table and the phone cards read the same two hooks. '
                    .'**Until 7 Sep 2026 `rowUrl()` was declared and called by nothing**, so the shipped user list rendered 90 people as plain text with no way to open any of them — which is also why this component sat in "not built yet" while every list screen used it.',
                'js' => 'data-grid.js, row-detail.js',
                // NOT in the gallery, and it cannot be: it takes a GridResult,
                // which GridService builds from a procedure or a query — and a
                // gallery component never touches the database (plan §3.8).
                // Faking one here would be a second GridResult that drifts from
                // the real thing. It has its own dev surface, /dev/grids, which
                // renders it over two real definitions.
                'gallery' => false,
                'props' => [
                    ['grid', 'GridResult', '—', 'What GridService built. The only data the component takes'],
                    ['branches', '?Collection', 'null', 'Sites the head-office selector offers (§3.3), or null for none'],
                    ['actions', 'slot', '—', 'Toolbar buttons, right of Extract'],
                    ['bulk', 'slot', '—', 'What to offer when rows are ticked'],
                ],
            ],
            [
                'name' => 'x-compare',
                'group' => 'Data',
                'summary' => 'Two readings of the same thing, side by side, with one of them in force.',
                'notes' => 'Built for the extraction configuration, where Agora SHADOWS the customer\'s '
                    .'`BRN_AutoReconCriteria` rather than writing it — so the configuration has two sources of '
                    .'truth and a screen showing only the effective value would hide a divergence. '
                    .'`live` says which side is actually in force and is carried as a class rather than only in '
                    .'the heading, because "which of these two is real" is the question the reader arrives with. '
                    .'The moment anything else in Agora shadows a legacy value it wants this same shape.',
                'props' => [
                    ['left', 'slot', '—', 'The first reading'],
                    ['right', 'slot', '—', 'The second'],
                    ['leftTitle', '?string', 'null', 'Heading above the first'],
                    ['rightTitle', '?string', 'null', 'Heading above the second'],
                    ['live', '?string', 'null', "'left' or 'right' — which one is in force"],
                ],
            ],
            [
                'name' => 'x-modal',
                'group' => 'Structure',
                'summary' => 'A dialog: a form or a confirmation that needs the page kept behind it.',
                'notes' => 'Native `<dialog>`, so the browser owns the top layer, the backdrop, the focus trap, '
                    .'Escape and returning focus. `modal.js` adds only what it does not: opening from a '
                    .'`data-modal-open` control anywhere on the page, and fetching the body from `data-modal-url` '
                    .'when the content depends on which row was clicked. '
                    .'**Fetched EVERY open**, unlike `row-detail.js`\'s fetch-once — the reason to open an edit '
                    .'form is that what you saw last time may no longer be there. '
                    .'**When not to use it:** a modal interrupts. A row that merely wants to show more of itself '
                    .'belongs in `data-row-detail`, which expands in place and keeps the list visible.',
                'js' => 'modal.js',
                'props' => [
                    ['id', 'string', '—', 'The handle data-modal-open refers to'],
                    ['title', '?string', 'null', 'Heading, and the dialog\'s accessible name'],
                    ['wide', 'bool', 'false', 'For a form with two columns to compare'],
                ],
            ],
            [
                'name' => 'x-two-pane-recon',
                'group' => 'Data',
                'summary' => 'The reconcile workbench: outstanding bank at the left, undeclared deposits at the right, paired by hand.',
                'notes' => 'Fed by `agora.usp_Recon_GetSides`. '
                    .'**The colour is earned, not decorative** — a row is coloured only where the reference it resolves to appears on BOTH sides, so a hue means "there is something over there carrying this". '
                    .'Colouring every row by its own key would mean nothing and would cost the reader the same attention. '
                    .'**Never colour alone**: the reference travels beside it as a chip, because a colour cannot be read out, searched for, or seen by everybody. '
                    .'Rows arrive in colour order, so the two sides line up beside each other on first paint and the work is half done before anybody scrolls. '
                    .'Ten hues, cycled — the procedure\'s ColourIndex is a dense rank, and wrapping is honest: two distant groups sharing a hue is a smaller problem than a group with no hue. '
                    .'Drawn as a left rail plus a wash rather than a fill, because a filled row fights the theme\'s own striping and loses in dark mode. '
                    .'It opens NO form of its own: the totals bar, the reason field and the Match button belong to one submission, and a component that opened its own form would put them in two.',
                'js' => 'recon-match.js',
                'props' => [
                    ['bank', 'Collection', '[]', 'Bank rows — result set 1 of usp_Recon_GetSides'],
                    ['mops', 'Collection', '[]', 'Deposit rows — result set 2'],
                    ['summary', '?object', 'null', 'Result set 3: the counts, the totals and whether the branch is configured'],
                    ['keyLabel', 'string', "'Reference'", 'What this area calls its reference — Batch, Bag, Slip'],
                ],
            ],
            [
                'name' => 'x-table',
                'group' => 'Data',
                'summary' => 'A rendered result set.',
                'mockup' => 'dg',
                'notes' => '**Not `<x-data-grid>`** — no export and no column persistence, and its filtering is a different thing in a different place. It carries the parts of feature-rules §3 that apply to any table: its own scroll container, the name of its procedure (§3.4), its row count, and a deliberate empty state. **The head sticks**, which it had declared since it was written and never once did: `overflow-x: auto` alone makes the scroll container a container on both axes, and a header pinned to the top of a box whose height is its content never moves. The box has a height now. `tools` adds click-to-sort and an Excel-style filter row applied IN THE BROWSER over the rows already on the page — the right shape for a result set that arrived complete and carries tick boxes, and the wrong shape for ninety thousand rows, which is what `<x-data-grid>` is for. Opt-in, because filtering page one of nine and calling it a filter is a lie. A row a filter hides has its inputs disabled, so it leaves the submission and no commit can touch a row nobody can see. Extra attributes land on the `<table>`, so `data-row-detail` reaches `row-detail.js`.',
                'js' => 'row-detail.js, table-tools.js',
                'props' => [
                    ['procedure', '?string', 'null', 'The procedure that produced these rows'],
                    ['count', '?int', 'null', 'Rows shown'],
                    ['total', '?int', 'null', 'Rows there are, when more than shown'],
                    ['empty', 'string', "'Nothing to show.'", 'The empty state line'],
                    ['dense', 'bool', 'false', 'Tighter row padding'],
                    ['tools', 'bool', 'false', 'Click-to-sort and a per-column filter row, done in the browser'],
                    ['head', 'slot', '—', 'The <tr> of <th>'],
                ],
            ],
            [
                'name' => 'x-action-bar',
                'group' => 'Data',
                'summary' => 'The press that commits, above the rows it commits.',
                'mockup' => 'run-actions, moved',
                'notes' => 'Every reconcile screen put its primary action in a footer UNDER the table — on a month of ABSA, four hundred rows below the first thing the reader looks at, and the users said so. **Sticky rather than merely moved**: moving it up reads correctly on first paint and loses it again the moment anybody scrolls, and scrolling is exactly what somebody does before deciding they are finished. It pins under the chrome at `--sticky-top`, which `table-tools.js` writes from the MEASURED scope bar — that bar is not on every page and is not a fixed height, so a hard-coded offset is right on one screen only. **The count is live**: give it `for` and it recomputes on every tick and every column filter, from the boxes still in the submission. A button reading "Reconcile 138 batches" while a filter has left 12 of them submittable is worse than a button with no number on it. The server still renders the correct text, so it is right with no JavaScript at all; the `data-count-*` attributes only let it stay right. A control that GATES the press — the manual match\'s reason field — goes in the slot beside the button, because it appears at the moment the press stops being allowed without it. **The count is not the whole guard.** A filter narrowing a commit is deliberate, but the reverse mistake is just as real — narrow the list to look at something, forget, press, and stamp 12 where 138 were meant. The smaller number on the button is not a stop, so the commit form carries `data-confirm-filtered="&lt;table id&gt;"` (comma-separated for several) and `confirm-form.js` leads the confirmation with what the filter is holding back. Silent when nothing is narrowed, so a normal commit is never made to look dangerous. Ryan\'s call, 8 Sep 2026.',
                'js' => 'table-tools.js',
                'props' => [
                    ['for', '?string', 'null', 'The id of the <x-table> whose ticks this bar commits'],
                    ['sticky', 'bool', 'true', 'false leaves it in the flow, for a bar inside something that scrolls on its own'],
                    ['note', 'slot', '—', 'The sentence explaining what the press does'],
                ],
            ],
            [
                'name' => 'x-chip',
                'group' => 'Data',
                'summary' => 'Status pill.',
                'mockup' => 'chip + dot',
                'notes' => 'Tones: `neutral good warn serious crit`. The dot is part of the component rather than something a caller remembers, and it takes `currentColor` — so the pill is still legible to a reader who cannot separate the five tones by hue. `dot="false"` for a chip that is a label rather than a state.',
                'props' => [
                    ['tone', 'string', "'neutral'", 'neutral good warn serious crit'],
                    ['dot', 'bool', 'true', 'The leading dot'],
                ],
            ],
            [
                'name' => 'x-delta',
                'group' => 'Data',
                'summary' => 'A movement, with its direction.',
                'mockup' => 'delta up / dn / flat',
                'notes' => 'Both halves come from `App\Support\Format` — `delta()` writes the text and `deltaTone()` picks the class. `invert` is the point of it: up is good on turnover and bad on shrinkage, and only the caller knows which it is looking at. A null value is an em dash in the flat tone.',
                'props' => [
                    ['value', '?float', 'null', 'The movement, as a percentage'],
                    ['invert', 'bool', 'false', 'True where a fall is the good news'],
                    ['suffix', 'string', "'%'", 'What follows the figure'],
                    ['dp', 'int', '1', 'Decimal places'],
                ],
            ],
            [
                'name' => 'x-empty-state',
                'group' => 'Data',
                'summary' => '"Nothing here yet", designed like an answer.',
                'mockup' => 'emptystate',
                'notes' => 'With a `title` it becomes a centred panel with a headline; without one it stays a single paragraph, so it can sit in a table foot or a list without leaving a 44px hole.',
                'props' => [
                    ['title', '?string', 'null', 'Headline in the display face'],
                    ['text', 'string', "'Nothing here yet.'", 'The sentence under it'],
                ],
            ],
            [
                'name' => 'x-sqlbox',
                'group' => 'Data',
                'summary' => 'The procedure behind a screen, shown.',
                'mockup' => 'sqlbox',
                'notes' => 'feature-rules §3.4 in its strong form. It does **not** read the database — the body is a prop, because a component that went and fetched `sys.sql_modules` would run a query against a 249 GB production server on every page load. Collapsed by default: on a screen that has an answer, the SQL is provenance rather than the point.',
                'props' => [
                    ['procedure', '?string', 'null', 'agora.usp_… name'],
                    ['title', 'string', "'Report SQL — read only'", 'The summary line'],
                    ['open', 'bool', 'false', 'Start expanded'],
                ],
            ],
            [
                'name' => 'x-field',
                'group' => 'Parameters',
                'summary' => 'One labelled control with its explanation attached.',
                'mockup' => 'form',
                'notes' => '`type="bool"` renders a checkbox with a hidden 0 beside it. A plain `<select>` stays plain — `select.js` only claims `select[data-select]`. This is the full-size form field; `<x-param>` is the compact one that goes in a parameter grid. **`choices` also takes a LIST** of `[value, label, when]` rows, for the case a value-keyed map cannot express: two options with the same value. Counting areas are numbered per site, so the stock recon centre renders every site\'s at once and `linked` + `linked-select.js` narrow them in the browser rather than costing a page load per site. **`step` is not optional on a decimal**: a number input steps by 1 unless told otherwise, so a control declared `min="0.01" max="1.0"` holding the value 1 is invalid to the browser, and Chrome then refuses the whole form with "an invalid form control is not focusable" and no visible message — silently, if the field sits inside a closed `<details>`. That is exactly how the stock recon Preview button did nothing on 8 September 2026.',
                'props' => [
                    ['name', 'string', '—', 'Field name, and the id it derives'],
                    ['label', 'string', "''", 'Above the control'],
                    ['help', '?string', 'null', 'The line under it'],
                    ['type', 'string', "'text'", 'Any input type, or bool'],
                    ['choices', '?array', 'null', 'Renders a select'],
                    ['value', 'mixed', 'null', 'Current value'],
                    ['min', 'mixed', 'null', 'Input min'],
                    ['max', 'mixed', 'null', 'Input max'],
                    ['step', 'mixed', 'null', 'Input step — required on a decimal, or 1 is assumed'],
                    ['linked', '?string', 'null', 'Name of the select this one follows; needs `when` on the choices'],
                ],
            ],
            [
                'name' => 'x-params',
                'group' => 'Parameters',
                'summary' => 'The parameter block above a report or a grid.',
                'mockup' => 'params',
                'notes' => 'A responsive grid rather than a form layout, because the count varies from two to eight and both have to look deliberate. `collapsible` is a `<details>`, not a click handler; `remember` is what `disclosure.js` keys the open state on, since a report someone runs every morning should not fold itself away again each time.',
                'js' => 'disclosure.js',
                'props' => [
                    ['legend', '?string', 'null', 'Accessible name for the group'],
                    ['summary', 'string', "'Parameters'", 'The fold line, when collapsible'],
                    ['collapsible', 'bool', 'false', 'Wrap in a <details>'],
                    ['open', 'bool', 'true', 'Which way it starts'],
                    ['remember', '?string', 'null', 'disclosure.js key'],
                ],
            ],
            [
                'name' => 'x-param',
                'group' => 'Parameters',
                'summary' => 'One control inside a parameter block.',
                'mockup' => 'params .f / .f.dis',
                'notes' => '`disabled` is why this exists. A library where every report shows the same parameter set, dimmed where it does not apply, tells the reader which parameters exist; hiding them makes every report look like a different application. The slot overrides the control entirely for the cases the props do not cover.',
                'props' => [
                    ['name', '?string', 'null', 'Field name'],
                    ['label', 'string', "''", 'The uppercase label'],
                    ['type', 'string', "'text'", 'Any input type'],
                    ['choices', '?array', 'null', 'Renders a select'],
                    ['value', 'mixed', 'null', 'Current value'],
                    ['disabled', 'bool', 'false', 'Greyed — the report ignores this one'],
                    ['searchable', 'bool', 'false', 'Opts the select into TomSelect'],
                    ['placeholder', '?string', 'null', 'Input placeholder / select search hint'],
                    ['min', 'mixed', 'null', 'Input min'],
                    ['max', 'mixed', 'null', 'Input max'],
                    ['step', 'mixed', 'null', 'Input step'],
                    ['help', '?string', 'null', 'The line under it'],
                ],
            ],
            [
                'name' => 'x-runbar',
                'group' => 'Parameters',
                'summary' => 'The bar that runs the thing, and says that it is running.',
                'mockup' => 'runbar',
                'notes' => 'On submit `runbar.js` disables the button and swaps the status, because a procedure that takes four seconds gets pressed twice. The button is disabled on the next tick — a submit button disabled inside the handler is dropped from the payload — and released again on `pageshow`, or the back button lands on a permanently dead Execute. How long the run took is the server\'s to report through `status`; timing it in the browser measures the round trip and calls it the query.',
                'js' => 'runbar.js',
                'props' => [
                    ['action', 'string', "'Execute'", 'Primary button label'],
                    ['type', 'string', "'submit'", 'button or submit'],
                    ['status', 'string', "'Ready.'", 'The live-region line'],
                    ['busy', 'string', "'Executing…'", 'What it says while running'],
                    ['procedure', '?string', 'null', 'Named on the bar, per feature-rules §3.4'],
                    ['disabled', 'bool', 'false', 'Nothing to run'],
                    ['slot', 'slot', '—', 'Secondary actions'],
                ],
            ],
            [
                'name' => 'x-checklist',
                'group' => 'Queues',
                'summary' => 'The steps that have to be done before something can be closed.',
                'mockup' => 'day-close steps',
                'notes' => 'An `<ol>`, because the order is real — the Z-reads cannot be allocated before the POS files are imported. Each step carries a chip as well as a mark, because a green circle on its own is not a status anyone can read out. A step with no `href` renders as text rather than a dead link (feature-rules §3.7).',
                'props' => [
                    ['steps', 'array', '[]', "List of ['title','detail','done','href']"],
                    ['empty', 'string', "'No steps for this day.'", 'When there are none'],
                ],
            ],
            [
                'name' => 'x-exception-list',
                'group' => 'Queues',
                'summary' => 'The container for a run of exception rows.',
                'mockup' => 'exlist',
                'notes' => 'Separate from the row so that "nothing is open" is rendered once rather than by every screen. An empty exception register is the good outcome and should read like one. `scroll` caps the height so a branch console can show its open items without pushing the page off the screen.',
                'props' => [
                    ['empty', 'string', "'Nothing open.'", 'When the list is empty'],
                    ['scroll', 'bool', 'false', 'Cap the height and scroll inside'],
                ],
            ],
            [
                'name' => 'x-exception-row',
                'group' => 'Queues',
                'summary' => 'One row of the exception register.',
                'mockup' => 'ex / bar / body / t / m / d / v',
                'notes' => 'The mockup toggled a class on click; this is a `<details>`, so it opens on Enter and Space and announces itself as a disclosure. That is the one deviation from the sheet and it costs a single selector. Severity is the register\'s own vocabulary — critical, serious, warning, good — mapped to a chip tone here so no screen has to know that "warning" wears the warn tint.',
                'props' => [
                    ['severity', 'string', "'warning'", 'critical serious warning good'],
                    ['title', 'string', "''", 'What is wrong, in a line'],
                    ['detail', '?string', 'null', 'The explanation, behind the fold'],
                    ['category', '?string', 'null', 'Fuel, Stock, Banking…'],
                    ['site', '?string', 'null', 'Which branch, or Group'],
                    ['age', '?string', 'null', 'How long since it was raised'],
                    ['owner', '?string', 'null', 'The desk that carries it'],
                    ['value', '?string', 'null', 'What it is worth, already formatted'],
                    ['unit', '?string', 'null', 'What that figure is measured in'],
                    ['href', '?string', 'null', 'Where the detail lives'],
                    ['open', 'bool', 'false', 'Start expanded'],
                ],
            ],
            [
                'name' => 'x-decision-list',
                'group' => 'Queues',
                'summary' => 'Things waiting on a person rather than on the system.',
                'mockup' => 'needs a decision',
                'notes' => 'Deliberately not a grid. This is a short list a manager acts on this morning; a sortable table with header filters would put the decision behind a column chooser.',
                'props' => [
                    ['items', 'array', '[]', "List of ['who','what','detail','amount','href','tone']"],
                    ['empty', 'string', "'Nothing waiting.'", 'When there are none'],
                ],
            ],
            [
                'name' => 'x-proposal',
                'group' => 'Queues',
                'summary' => 'Something the system worked out, with how sure it is and why.',
                'mockup' => 'Z-read allocation',
                'notes' => 'Agora proposes and a person disposes, and all three parts of that contract are required: what it proposes, how confident it is, and the evidence. Confidence is a word — certain / likely / review / manual — not a percentage: the customer has no calibrated probability and a number invites it to be trusted. `manual` is a first-class answer, because "no history for this operator ID" is itself information. Accept and override go in the `actions` slot, since they are forms with permissions on them.',
                'props' => [
                    ['confidence', 'string', "'review'", 'certain likely review manual'],
                    ['headline', '?string', 'null', 'What it proposes, in one line'],
                    ['eyebrow', '?string', "'The system suggests'", 'The label above it'],
                    ['evidence', 'array', '[]', 'Why it thinks so, one reason per line'],
                    ['actions', 'slot', '—', 'Accept / override forms'],
                ],
            ],
            [
                'name' => 'x-lib-card',
                'group' => 'Queues',
                'summary' => 'One entry in the report library.',
                'mockup' => 'libcard / libmeta',
                'notes' => '`was` is the point of it, not a nicety: the library is where the customer finds out that the report they used to run is now called something else, and it is the only bridge between 161 legacy names and the 97 that replace them. `data-s` carries the searchable text so the filter box covers the same fields on every category.',
                'props' => [
                    ['name', 'string', "''", 'The report name'],
                    ['desc', '?string', 'null', 'What it answers'],
                    ['scope', '?string', 'null', 'Group, branch, period…'],
                    ['was', 'array', '[]', 'The legacy names it replaces'],
                    ['tags', 'array', '[]', "['tone','label'] each"],
                    ['href', '?string', 'null', 'Where it runs'],
                ],
            ],
            [
                'name' => 'x-role-card',
                'group' => 'Queues',
                'summary' => 'A large tappable choice.',
                'mockup' => 'rolebtn',
                'notes' => 'An `<a>` when it is a destination and a `<button>` when it submits — never a div with a click handler. Written for the mockup\'s role picker; the shipped sign-in is email and password, so its first real use is likely to be a branch or a run picker on a phone.',
                'props' => [
                    ['label', 'string', "''", 'The choice'],
                    ['who', '?string', 'null', 'Who it is for'],
                    ['detail', '?string', 'null', 'What choosing it does'],
                    ['href', '?string', 'null', 'Renders an <a> instead of a <button>'],
                    ['type', 'string', "'submit'", 'Button type when there is no href'],
                ],
            ],
            [
                'name' => 'x-system-state',
                'group' => 'Queues',
                'summary' => 'What the system knows before anybody has signed in.',
                'mockup' => 'signin-state / sdot',
                'notes' => 'Overnight loads, exceptions open, Z-reads unallocated. It sits on the sign-in hero on purpose: the first useful thing Agora can tell a branch manager at 05:00 is whether last night\'s loads landed, and making them authenticate to find out is a screen designed for the system. Every row carries text as well as a dot.',
                'props' => [
                    ['title', '?string', 'null', 'The eyebrow above the rows'],
                    ['rows', 'array', '[]', "List of ['tone','text','href']; tones good warn crit"],
                ],
            ],
            [
                'name' => 'x-notice',
                'group' => 'Messages',
                'summary' => 'Something the reader must take in before the figures below mean what they look like.',
                'mockup' => 'note',
                'notes' => 'A refusal, a step deliberately not taken, a count that is evidence. Not an error page. `collapsible` folds the reasoning behind the headline and the headline stays visible, because nobody should have to open something to learn a problem exists. `<details>`, so no JS. The mockup\'s bare `.note` class is `tone="info"` and is still styled for markup ported from the sheet.',
                'props' => [
                    ['tone', 'string', "'warn'", 'warn stop info'],
                    ['title', '?string', 'null', 'The headline'],
                    ['collapsible', 'bool', 'false', 'Fold the body'],
                    ['open', 'bool', 'false', 'Which way it starts'],
                ],
            ],
            [
                'name' => 'x-tip',
                'group' => 'Messages',
                'summary' => 'The page\'s one floating tooltip.',
                'mockup' => 'tip',
                'notes' => 'A singleton, because a chart with 31 bars must not mount 31 tooltips and because flipping at the edge needs a measurable element. `data-tip="…"` on anything shows it on hover **and on focus** — the mockup\'s was hover-only, which no keyboard can reach — and `window.Agora.tip.show(event, html)` is the way a chart draws its own rows into it. Hidden entirely below 720px: a fixed box on a phone covers the answer, and a tooltip only ever repeats something written somewhere permanent.',
                'js' => 'tip.js',
                'props' => [],
            ],
            [
                'name' => 'x-reports::cell',
                'group' => 'Module-scoped',
                'summary' => 'One table cell, formatted from the column\'s declared type.',
                'mockup' => 'dg td',
                'notes' => 'Types: `text number money litres date datetime bool chip`. Every figure goes through `App\Support\Format`, never `number_format`; a missing value is an em dash, never `R0.00`. `chip` maps a procedure\'s Status wording to a tone by keyword, so a new phrase falls to neutral rather than to a fatal.',
                'gallery' => false,
                'props' => [
                    ['type', 'string', "'text'", 'The column\'s declared type'],
                    ['value', 'mixed', 'null', 'The raw value from the procedure'],
                ],
            ],
        ];

        return self::$cache = collect($components)
            // `mockup` defaults too, and it has to: a component built here rather
            // than lifted from ZP's design has no mockup to name, and the
            // gallery reads the key unguarded — so an entry without one took
            // /dev/components down with an undefined-array-key 500 rather than
            // rendering without the label.
            ->map(fn (array $c) => $c + ['mockup' => null, 'notes' => '', 'js' => null, 'gallery' => true, 'props' => []])
            ->keyBy('name')
            ->all();
    }

    /**
     * What is not built, and who owns it.
     *
     * Named here so the next session does not invent a second version of one.
     * The gallery renders a labelled slot for each, so the reviewer can wire in
     * a lane's component at merge without redesigning the page around it.
     *
     * @return array<int, array{name: string, owner: string, what: string}>
     */
    public static function pending(): array
    {
        return [
            ['name' => 'x-drawer', 'owner' => 'T014', 'what' => 'The extract panel — the CSV and the copy-to-clipboard that every grid opens.'],
            ['name' => 'x-chart', 'owner' => 'T015', 'what' => 'ApexCharts behind one component: daily bars, line, donut, bridge, diverging. Colours read from the theme tokens at draw time, so a chart follows the light/dark switch.'],
            ['name' => 'x-sparkline', 'owner' => 'T015', 'what' => 'The 30px trend inside a KPI tile. `<x-kpi>` already has the `spark` slot it goes in.'],
            ['name' => 'x-mini-bar', 'owner' => 'T015', 'what' => 'The in-cell bar with a zero line, for a variance column.'],
            ['name' => 'x-palette', 'owner' => 'T012', 'what' => 'Ctrl-K search over every screen, report and master.'],
        ];
    }
}
