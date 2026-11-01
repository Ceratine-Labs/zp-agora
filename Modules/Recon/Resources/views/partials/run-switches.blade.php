@php
    $mine = \Modules\Recon\Grids\ReconRunGrid::wantsOwnRunsOnly();
    $invalidOnly = \Modules\Recon\Grids\ReconRunGrid::wantsInvalidOnly();
    $openOnly = \Modules\Recon\Grids\ReconRunGrid::wantsOpenOnly();
@endphp
<x-tabs label="Which runs"
        :active="$invalidOnly ? 'invalid' : ($openOnly ? 'open' : 'all')"
        :items="[
            ['key' => 'open', 'label' => 'Open work', 'href' => $grid->urlWith(['show' => null, 'validity' => null, 'page' => null])],
            ['key' => 'invalid', 'label' => 'Invalid', 'href' => $grid->urlWith(['show' => 'all', 'validity' => 'invalid', 'page' => null])],
            ['key' => 'all', 'label' => 'Everything', 'href' => $grid->urlWith(['show' => 'all', 'validity' => null, 'page' => null])],
        ]" />
<x-tabs label="Whose runs"
        :active="$mine ? 'mine' : 'all'"
        :items="[
            ['key' => 'mine', 'label' => 'Mine', 'href' => $grid->urlWith(['scope' => null, 'page' => null])],
            ['key' => 'all', 'label' => 'Everyone', 'href' => $grid->urlWith(['scope' => 'all', 'page' => null])],
        ]" />
