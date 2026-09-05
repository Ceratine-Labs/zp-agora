{{--
    The column chooser (feature-rules §3.6).

    A <details>, so the browser supplies the open/close and the keyboard, and
    the panel is real markup that exists before any script runs. What the script
    adds is the persistence and the immediate hide/show; without it the checkbox
    is still a checkbox and the ↑ ↓ still reorder — they just do not survive the
    page, which is the right thing to lose first.

    Order is nudged with ↑ and ↓ rather than dragged. Drag is the obvious
    gesture on a desktop and unusable on a phone, and this is a control the
    mobile card list needs as much as the table does — the cards show the first
    three visible columns, so "which three" IS this panel.

    Props: $grid, $slug
--}}
@php
    $sizes = config('grids.page_sizes', [25, 50, 100, 200]);
@endphp

<details class="dg-chooser" data-chooser>
    <summary class="btn-ghost">Columns</summary>

    <div class="dg-chooser-panel">
        <p class="dg-chooser-note">Yours, on this grid, on any device you sign in to.</p>

        <ul class="dg-chooser-list" data-chooser-list>
            @foreach ($grid->columns as $view)
                <li data-chooser-item="{{ $view->key() }}">
                    <label>
                        <input type="checkbox" data-chooser-visible value="{{ $view->key() }}" @checked($view->visible)>
                        <span>{{ $view->column->label }}</span>
                    </label>
                    <button type="button" class="dg-nudge" data-chooser-up aria-label="Move {{ $view->column->label }} earlier">↑</button>
                    <button type="button" class="dg-nudge" data-chooser-down aria-label="Move {{ $view->column->label }} later">↓</button>
                </li>
            @endforeach
        </ul>

        <div class="dg-chooser-foot">
            <label class="dg-chooser-size">
                Text
                <select data-text-size>
                    @foreach (['compact' => 'Compact', 'normal' => 'Normal', 'large' => 'Large'] as $key => $label)
                        <option value="{{ $key }}" @selected($grid->textSize === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="dg-chooser-size">
                Rows
                <select data-page-size>
                    @foreach ($sizes as $size)
                        <option value="{{ $size }}" @selected($grid->gridQuery->pageSize === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Reset removes the row rather than storing an empty layout: "put
                 it back" has to mean the catalogue's own order and auto widths,
                 and a stored empty map replays the old widths on the next load. --}}
            <button type="button" class="btn-ghost" data-chooser-reset>Reset</button>
        </div>
    </div>
</details>
