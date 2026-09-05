# Charts

Everything a screen needs to draw one, and the decisions behind it. The gallery
is `/dev/charts` (local and testing only) and it draws every type from the
mockup's own August 2026 consolidation.

---

## Rows for `docs/components.md`

*(Lane B owns that file. These rows are here for the reviewer to merge, not to
be copied twice — a second copy of a source of truth always drifts.)*

Under **Shipped**:

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-chart>` | `type`, `series`, `options`, `title`, `sub`, `height`, `empty`, `table` | One chart, over ApexCharts | Types: `daily-bars` `line` `donut` `mix` `bridge` `diverging`. Every colour is a token reference resolved at draw time, so a chart follows the light/dark switch; a hex literal in the payload throws. Every chart ships a table of the same figures behind a `<details>` — the accessible twin, and the relief for the light-theme series colours that measure under 3:1. No rows means an empty state and **no** `[data-chart]`, so the library is never fetched. Full detail in [`charts.md`](charts.md) |
| `<x-sparkline>` | `values`, `tone`, `width`, `height`, `fill`, `label` | A trend, inline SVG, no library | Four elements. Takes `currentColor` by default, so inside a KPI card it wears the card's colour; `tone` pins it to a token (`s1`…`s5`, `good`, `warn`, `crit`, `muted`). A gap breaks the line rather than being interpolated across. No readings renders `—`, never a flat line |
| `<x-mini-bar>` | `value`, `max`, `width`, `height`, `label` | An in-cell variance bar, inline SVG, no library | Three elements, once per grid row. **`max` is the column's, not the row's** — a per-row scale makes every row's longest bar look the same. Over is `--good` and right of the tick, under is `--crit` and left, so it is never colour alone. On budget draws the tick and no bar |

In the **Libraries** table, `components/chart.js` gains: *"Colours are token
references resolved against the live document at draw time, and every chart is
torn down and rebuilt when the theme moves — a MutationObserver on `data-theme`
and a `prefers-color-scheme` listener, because the default state stamps nothing."*

In **Not built yet**, remove `<x-chart>` over ApexCharts (T015).

**One thing for Lane B to weigh, not a row:** `.kpi .kpi-value` carries
`font-variant-numeric: tabular-nums` at 26px. Tabular figures give every digit
the width of a `0`, which is right in a column and loose on a standalone
display-size number — `121` reads with a gap in it. The dataviz rule is
proportional on hero and tile values, tabular only where numbers align
vertically. `_components.scss` is Lane B's file, so this is a note rather than
an edit.

---

## The six types

`series` is always a list of rows carrying `label` and `value`, so a controller
writes the same shape whichever chart it is feeding.

| Type | `series` | For |
|---|---|---|
| `daily-bars` | `['label' => '01', 'value' => 160998, 'quiet' => true, 'note' => 'Sat']` | A day per column. `quiet` dims a weekend |
| `line` | `['name' => 'Actual', 'points' => [['label' => …, 'value' => …], …]]` | One or more series over time |
| `donut` | `['label' => 'ULP 95', 'value' => 1920971]` | Part of a whole, ≤ 6 slices |
| `mix` | `['label' => 'Fuel', 'values' => ['Last year' => …, 'Budget' => …, 'Actual' => …]]` | Several measures per category |
| `bridge` | `['label' => 'Budget GP', 'value' => …, 'total' => true, 'note' => …]` | A walk from one total to another |
| `diverging` | `['label' => 'Nyala', 'value' => 4.77]` | Everything against one baseline |

### `options`

| Option | Applies to | What it does |
|---|---|---|
| `format` | all | The `App\Support\Format` method for values, tooltips, data labels and the table. `'Rk'` or `['cpl', 3]` |
| `axisFormat` | all | The format for axis ticks. Defaults to `format` — a c/ℓ line usually wants three places in the tooltip and two on the axis |
| `label` | all | The `aria-label`. SVG has no alt text and "chart" is not a description |
| `labelHeading` | all | The first column heading of the table twin |
| `name` | all but `line`, `mix` | The series name, in the legend and the tooltip |
| `caption` | all | A sentence under the chart. A bridge appends its own to whatever you pass |
| `average` | `daily-bars` | Window for the moving-average line, in points. `7` for a week |
| `averageName` | `daily-bars` | What that line is called |
| `reference` | `line` | `['value' => 2.143, 'label' => 'Budget']` — drawn as a dashed annotation |
| `measures` | `mix` | The measure names, in reading order. Defaults to the first row's key order |
| `centreLabel`, `centreFormat` | `donut` | The word and the format under the total in the middle |
| `apex` | all | Raw ApexCharts overrides, merged last. **Colour literals are refused** — pass `token:--s1` |

Anything not in that list, and any format not in `Format`, throws by name rather
than being ignored.

---

## How a colour gets into a chart

It does not — not as a value, anywhere.

```
 <x-chart>                  chart.js                        ApexCharts
 ─────────                  ────────                        ──────────
 ChartSpec::payload()  →   build(spec)                  →   new ApexCharts(el, options)
   colours written as        merge(base, builder, apex)
   "token:--s1"              findColourLiterals()  ← fails loudly if any survive
                             resolveTokens()       ← getComputedStyle(:root) HERE
                                 "token:--s1"  →  "#2a78d6"   (light)
                                 "token:--s1"  →  "#3987e5"   (dark)
```

`resolveTokens` walks the finished option tree and replaces every
`token:--name` string, so a future chart type cannot quietly hard-code one and
`findColourLiterals` can then prove nothing did. `token:--s1@0.55` resolves to
the same token at an alpha, which is how a weekend column is dimmed without
inventing a sixth series colour.

The rule is enforced on both sides of the wire:

- **PHP.** `App\Support\Chart\ChartSpec` throws `InvalidArgumentException` if a
  hex reaches the payload, including through a caller's own `apex` overrides.
- **JavaScript.** `chart.js` stamps `data-chart-colour-literal` on any holder
  whose finished options still carry one, and logs the path. `charts.spec.js`
  fails on the attribute.
- **The tests.** `ChartComponentTest::test_no_chart_on_the_gallery_page_carries_a_colour_literal`
  runs the regex over every holder on a real rendered page.

### Following the theme

Resolving at draw time is only half. A chart drawn in light and then looked at in
dark holds the colours it was born with, because ApexCharts has never heard of
`data-theme`. So `chart.js` watches for the two ways the theme can move —

- a **MutationObserver** on `<html>`'s `data-theme`, which is what `theme.js`
  stamps on an explicit choice, and which fires no event of its own;
- a **`prefers-color-scheme` listener**, because the default state stamps
  nothing at all and follows the operating system.

— and on either, tears every chart down and rebuilds it. Rebuilt rather than
patched through `updateOptions`, because a waterfall's semantic fills, an
annotation's border, a per-point fill and the tooltip theme live in four
different corners of the option tree and a partial merge that misses one leaves
a chart half in the other theme.

The tooltip and legend chrome ApexCharts injects for itself is not reachable
through its options API at all, so `_charts.scss` repoints it at the tokens,
where a custom property does the theme switch for free.

---

## Numbers

Every figure — axis tick, tooltip, data label, donut centre, table twin — goes
through `App\Support\Format` or its twin `resources/js/format.js`. `Intl` is
banned and so is `number_format`; the reason is written out in both files.

The payload names a format and `chart.js` looks it up, so a screen can pick one
but cannot invent one. An unknown name is an exception in PHP and a
`console.error` plus a fallback to the plain number format in the browser.

**A missing figure is an em dash.** A null value stays null all the way through
— it is not coerced to zero anywhere in the chart path — and comes out as `—` in
the tooltip and in the table.

**No format was missing.** Every figure the six types needed was already in the
pair: `n` `R` `Rk` `Lk` `litres` `pct` `cpl` `delta`. Nothing was added to
either half.

One thing to know rather than to fix: on the bridge, `Rk` renders the R25.5m
ends and a R37 640 step in the same format, so the step reads as `-R38k`. That
is `Rk` doing its job, and a bridge that wants exact rands passes `'format' =>
'R'`. It is a prop for that reason.

---

## The two that are not ApexCharts primitives

### The bridge

**Built on `chart.type: 'waterfall'`, which ApexCharts 7 has natively.** The
brief for this work assumed it did not and expected the usual trick — a stacked
bar with a transparent base series. That trick has two real costs and the native
type avoids both: the phantom base shows up in the legend and the tooltip, and a
step that crosses zero needs a **negative** base, which a stacked chart renders
below the axis instead of as a floating bar.

A row carries the signed step; `isTotal` marks a bar measured from zero and the
library measures it. `plotOptions.waterfall.colors` takes semantic fills —
positive, negative, total — which is exactly the shape needed, and
`plotOptions.waterfall.connectors` draws the dashed segments between the steps
that make it read as one walk rather than five unrelated columns. The mockup
drew those by hand.

**What it costs at the edges:**

- **The axis does not start at zero.** Against a R25.5m budget the four middle
  steps are between 0.1% and 6% of the ends; a zero-based axis draws them as
  hairlines. The window is the range the running total occupies, with headroom.
  A truncated axis that does not declare itself is a lie, so `<x-chart>` prints
  the sentence under every bridge and the caller **cannot switch it off** — only
  add to it. `ChartSpecTest` asserts that.
- **A step of exactly zero draws no bar.** There is no honest geometry for it, so
  the label carries it and the bar does not. A hairline would say "a small
  effect", which is a different answer.
- **A negative step is drawn correctly** — the bar hangs from the previous
  running total down to the new one — but the running total itself going
  negative has not been exercised. Every fixture we have keeps it positive.

### The diverging bar

A horizontal bar chart with signed values. Apex draws negatives to the left of
zero on its own; what it does not do is the part that makes it a diverging chart
rather than a bar chart with some negatives in it:

- **The scale is forced symmetric** — `min = -max, max = +max`. Left alone Apex
  fits the axis to the data, so a set running −1% to +6% puts zero a sixth of the
  way in and a −1% bar looks longer than a +1% one. Comparing the two sides is
  the whole point of the form.
- **The one-sided case is anchored at zero instead.** When nothing crosses the
  line — every site over budget — a symmetric scale wastes half the width and
  squashes the bars into the other half. There is nothing to diverge from, so it
  becomes an ordinary bar chart. The mockup handles this case explicitly and so
  does this.

Colour is `--good` / `--crit` by sign, declared as `plotOptions.bar.colors.ranges`
rather than computed per point, and it is doubled by which side of the line the
bar sits on, so it is never colour alone.

**What it costs:** the sort is the caller's. An unsorted diverging chart is a bar
chart with some negatives in it, and the component does not sort for you because
only the caller knows whether the order is by variance, by size or by region.

---

## The two that are not charts at all

`<x-sparkline>` and `<x-mini-bar>` are hand-rolled inline SVG. They go inside
KPI cards and grid cells, where a chart library **per instance** would be
absurd — a 900 KB download and a canvas per row to draw twelve points.

Their geometry is `App\Support\Chart\Sparkline` and `App\Support\Chart\MiniBar`,
which means it is exactly testable: the numbers in the `d` attribute *are* the
drawing. The four cases that break a hand-rolled sparkline are each handled
deliberately rather than falling out of the arithmetic, and each has a test:

| Case | What breaks | What we do |
|---|---|---|
| **Empty series** | Nothing to divide by | Render `—`. A missing trend is missing — not flat, not zero |
| **A single point** | The x step divides by `(n − 1)` | One dot in the middle |
| **A flat series** | The y scale divides by `(max − min)` | Draw through the vertical centre. The usual `range \|\| 1` guard puts the line on the **floor**, which reads as a collapse to zero — the opposite of what a flat month means |
| **A negative** | Only survives if the baseline is the series minimum | It is. A sparkline is a shape and has no zero line |

A fifth, because a real fuel dataset produces it: **a gap**. A null breaks the
line into a new subpath rather than being interpolated across — a straight
segment over a missing day is a reading nobody took — and the area wash is
withheld, because an area under a broken line has no honest boundary.

The mini bar's zero is a **real** zero, unlike the sparkline's, so two rows in
the same column are comparable. That only holds if every bar shares the column's
`max`, which is why `max` is a required argument rather than derived per row.
Its own edges: `max` of zero draws nothing (deriving a scale from the value would
make every bar full width), exactly on budget draws the tick and no bar (a
hairline would read as a small miss), a value too small to see still gets a
hairline (so "tiny" and "on budget" look different), and a value past the scale
is clamped and marked (so "the worst in this column" and "off the end" look
different).

The mockup's sparkline uses a `<linearGradient>` with a random id. This one uses
a flat fill at 10% instead: fewer elements, no `<defs>`, and no id to collide
with the other twelve sparklines on the page — and a server-rendered random id
is not stable enough to test.

---

## Accessibility and the dataviz rules

The `dataviz` skill's palette guidance is a default to be **replaced** by
Agora's tokens, not layered over them. The tokens win. What the skill's own
validator says about them, measured rather than eyeballed
(`node scripts/validate_palette.js` from the skill):

| Check | Light (`--s1`…`--s5` on `#ffffff`) | Dark (on `#151b1e`) |
|---|---|---|
| Lightness band | pass | pass |
| Chroma floor | pass | pass |
| CVD separation, **adjacent** | pass — worst `--s4`↔`--s3` ΔE 9.1 | pass — worst `--s4`↔`--s3` ΔE 8.4 |
| Normal-vision floor, **adjacent** | pass — worst 19.6 | pass — worst 19.3 |
| Contrast vs surface | **warn** — `--s3` 2.82, `--s4` 2.17, `--s5` 2.69 | pass, all ≥ 3:1 |

**Three findings, none of them a reason to change a token:**

1. **Adjacent-pair separation passes in both themes**, which is the check that
   matters given the palette is assigned in fixed order — `--s1` first, always,
   never cycled by rank. That ordering is already how the mockup uses it.
2. **All-pairs separation fails in both themes**, and worse in dark: `--s5`↔`--s3`
   is ΔE 1.6 under deuteranopia. So **four categorical series is the working
   ceiling**, not five, and a chart that puts non-adjacent slots side by side —
   a donut, a stacked bar — needs the legend and direct labels that every chart
   here already carries. Nothing we draw uses more than three.
3. **The light-theme contrast warning obligates relief, and it is not
   dismissable.** The relief is the table twin on every chart plus a legend
   whenever there is more than one series. That is why the twin is on by default
   rather than opt-in.

A fourth, about the status tokens rather than the series ones:
**`--warn` (`#fab219`) measures 1.83:1 on white and is outside the lightness
band.** As a chart *fill* on a light surface it is close to invisible. Charts
therefore use `--warn` only where a mark is paired with a label, and
`--warn-ink` for anything textual; `.spark-warn` uses `--warn-ink` for exactly
this reason. `--good` and `--crit` measure fine in both themes.

Other rules followed, each a deliberate change from what was here before:

- **Gridlines are solid hairlines.** `chart.js` had `strokeDashArray: 3`. A
  dashed grid reads as a projection or a threshold; the one dashed line on an
  Agora chart is the budget reference, which genuinely is one.
- **One axis, always.** The moving average on the daily chart rides the same
  scale as the columns because it is the same measure. No chart here has two.
- **Legend for two or more series, none for one.** A single-swatch legend
  restates the title.
- **Labels selectively.** The bridge is the only chart that labels every mark,
  because it has five and each one is the story.
- **Marks are thin**: bars capped at 24px with a 4px radius on the data end
  only, lines 2px, markers 8px on hover with a 2px surface ring, area fills at
  10%, a 2px surface gap between touching marks rather than a stroke around them.
- **Tooltips enhance, never gate.** Every value is in the table twin.

---

## What is proved, and what is not

`composer check-fast`, `phpstan`, `npm run build` and
`php artisan test --filter=…` prove everything that decides what the browser
will draw: the payload, the token references, the absence of a colour literal on
a real rendered page, the empty state, the table twin's figures, and the SVG
geometry of both hand-rolled marks including all four edge cases.

They do **not** prove the acceptance itself — "renders in both themes with the
right series colours and responds to container resize". That needs a document.
`tests/e2e/charts.spec.js` is written to it and has not been run; browser runs
are Ryan's call. Until it is run, treat the visual half as unverified.
