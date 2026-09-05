<?php

namespace App\Grid;

/**
 * A catalogue column as this user has it — shown or not, and how wide.
 *
 * The catalogue is what the grid IS; this is what one person has made of it.
 * They are separate objects because the catalogue is shared and immutable and
 * the layout is not: merging the two would mean a saved width on one request
 * leaking into the next person's columns through the container.
 */
final class GridColumnView
{
    public function __construct(
        public GridColumn $column,
        public bool $visible,
        public ?int $width = null,
    ) {}

    public function key(): string
    {
        return $this->column->key;
    }

    /**
     * The inline width, or an empty string for auto layout.
     *
     * Fixed table layout is what makes a pixel width mean anything at all
     * (feature-rules §3.6): under auto layout the browser re-divides the space
     * and a saved width is only a suggestion. So the table is `fixed` as soon
     * as ANY column has a width, and a column without one is left to the
     * browser rather than given a guess.
     */
    public function style(): string
    {
        return $this->width === null ? '' : 'width:'.$this->width.'px';
    }
}
