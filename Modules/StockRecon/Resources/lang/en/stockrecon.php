<?php

return [

    'name' => 'Stock recon centre',

    'no_branch_to_clear' => 'Choose a site before clearing its previews — there is no "every site" sweep here, '
        .'deliberately: a balancing preview belongs to one site\'s counts.',

    /*
    | The one sentence that has to be right wherever it appears. Balancing
    | MOVES variance between shifts and never creates or destroys it, so the
    | total across the window is fixed the moment the dates are chosen.
    */
    'invariant' => 'Balancing moves variance between shifts. It never creates or destroys it — the total '
        .'across the window is fixed the moment you choose the dates.',
];
