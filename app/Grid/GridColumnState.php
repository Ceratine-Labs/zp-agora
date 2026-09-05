<?php

namespace App\Grid;

/**
 * The user's layout for one grid — order, hidden columns, widths, text size —
 * and the whitelist that stands between the browser and `agora.UserGridColumn`.
 *
 * Every rule below is one ZP learned the hard way, and feature-rules §3.6
 * lists them because each cost a bug there:
 *
 *  - **Whitelist what is stored.** Only the keys named in KEYS survive; an
 *    `evil` key does not, and a test asserts it. A user-controlled JSON blob
 *    written straight into a column is a payload for whatever reads it later,
 *    and what reads this is a blade template.
 *  - **Bound the widths.** 5px and 5000px are both rejected. A width out of
 *    range is a bug or a fiddle, and either way it makes a grid the user then
 *    cannot use to fix it.
 *  - **An empty widths map is stored as absent, never as `{}`.** "Reset column
 *    widths" has to fall back to auto layout; an empty map replays the old
 *    widths on the next load because the table is then still `fixed`.
 *  - **A column brought back after a resize must not render at zero.** The
 *    widths map only ever holds columns that had one, so a re-shown column has
 *    no width and inherits auto sizing rather than collapsing.
 *
 * The class holds no state of its own: it cleans, and it applies. Anything it
 * does not recognise is dropped silently, because a saved layout is a
 * convenience and a malformed one must degrade to the default rather than
 * fail a page load.
 */
final class GridColumnState
{
    /** The only keys that are ever written to the database. */
    public const KEYS = ['column_order', 'hidden', 'widths', 'text_size', 'page_size', 'sort', 'dir'];

    public const TEXT_SIZES = ['compact', 'normal', 'large'];

    /**
     * Clean a submitted layout against a definition.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitise(array $input, GridDefinition $definition): array
    {
        $catalogue = $definition->catalogue();
        $known = array_keys($catalogue);
        $clean = [];

        // Order: known column keys, each once, in the order given. Columns the
        // user's saved order does not mention keep their catalogue position,
        // which is what makes a NEW column appear rather than vanish for
        // everyone who saved a layout before it existed.
        if (is_array($input['column_order'] ?? null)) {
            $order = collect($input['column_order'])
                ->reject(fn (mixed $k) => is_array($k) || is_object($k))
                ->map(fn (mixed $k) => (string) $k)
                ->filter(fn (string $k) => in_array($k, $known, true))
                ->unique()
                ->values()
                ->all();

            if ($order !== []) {
                $clean['column_order'] = $order;
            }
        }

        // `hidden` is kept even when it is empty, unlike `widths`. Its PRESENCE
        // is the statement "the user has chosen" — an empty list means they
        // turned everything back on, which is a different answer from never
        // having opened the chooser and must not be collapsed into it.
        if (is_array($input['hidden'] ?? null)) {
            $clean['hidden'] = collect($input['hidden'])
                ->reject(fn (mixed $k) => is_array($k) || is_object($k))
                ->map(fn (mixed $k) => (string) $k)
                ->filter(fn (string $k) => in_array($k, $known, true))
                ->unique()
                ->values()
                ->all();
        }

        if (is_array($input['widths'] ?? null)) {
            $min = (int) config('grids.width.min', 60);
            $max = (int) config('grids.width.max', 640);
            $widths = [];

            foreach ($input['widths'] as $key => $width) {
                $key = (string) $key;

                if (! in_array($key, $known, true) || ! is_numeric($width)) {
                    continue;
                }

                $width = (int) round((float) $width);

                if ($width < $min || $width > $max) {
                    continue;
                }

                $widths[$key] = $width;
            }

            // Absent, not `{}` — see the class comment.
            if ($widths !== []) {
                $clean['widths'] = $widths;
            }
        }

        if (in_array($input['text_size'] ?? null, self::TEXT_SIZES, true)) {
            $clean['text_size'] = $input['text_size'];
        }

        /** @var array<int, int> $sizes */
        $sizes = config('grids.page_sizes', [25, 50, 100, 200]);

        if (is_numeric($input['page_size'] ?? null) && in_array((int) $input['page_size'], $sizes, true)) {
            $clean['page_size'] = (int) $input['page_size'];
        }

        if (in_array($input['sort'] ?? null, $definition->sortValues(), true)) {
            $clean['sort'] = $input['sort'];
        }

        if (in_array($input['dir'] ?? null, ['asc', 'desc'], true)) {
            $clean['dir'] = $input['dir'];
        }

        return $clean;
    }

    /**
     * The catalogue, in the user's order, carrying their visibility and widths.
     *
     * Returns EVERY column, hidden ones included — the chooser has to list what
     * is off as well as what is on, and the export has to know which columns
     * the user is looking at. Hiding happens in the view, from `visible`.
     *
     * @param  array<string, mixed>  $state
     * @return array<int, GridColumnView>
     */
    public static function apply(array $state, GridDefinition $definition): array
    {
        $catalogue = $definition->catalogue();

        /** @var array<int, string> $order */
        $order = is_array($state['column_order'] ?? null) ? $state['column_order'] : [];
        /** @var array<int, string> $hidden */
        $hidden = is_array($state['hidden'] ?? null) ? $state['hidden'] : [];
        /** @var array<string, int> $widths */
        $widths = is_array($state['widths'] ?? null) ? $state['widths'] : [];

        // The saved order first, then anything the catalogue has gained since.
        $keys = array_values(array_filter($order, fn (string $k) => isset($catalogue[$k])));
        $gained = [];

        foreach (array_keys($catalogue) as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
                $gained[] = $key;
            }
        }

        // A column the saved layout has never seen — one added to the
        // catalogue after the user last chose — falls back to its own default
        // rather than being read out of a `hidden` list that could not have
        // mentioned it. Without this, every new column would arrive switched
        // on for everybody who had ever opened the chooser, including the ones
        // deliberately shipped off.
        return array_map(fn (string $key) => new GridColumnView(
            column: $catalogue[$key],
            visible: in_array($key, $gained, true) || ! array_key_exists('hidden', $state)
                ? $catalogue[$key]->visible
                : ! in_array($key, $hidden, true),
            width: isset($widths[$key]) ? (int) $widths[$key] : null,
        ), $keys);
    }
}
