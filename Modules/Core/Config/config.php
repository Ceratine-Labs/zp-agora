<?php

return [
    'name' => 'Core',

    /*
     | The two workspaces from plan §2. Head Office sees every site the user is
     | granted; Branch is pinned to one. Each carries its own menu sections.
     */
    'workspaces' => [
        'ho' => 'Head Office',
        'branch' => 'Branch',
    ],

    /*
     | The four figures on the sign-in page, in the order the mockup shows
     | them.
     |
     | These are BADGE KEYS, not classes. Core registers a placeholder for each
     | one and the module that owns the figure registers over it — Imports for
     | the loads, Exceptions for the register, Cash for the Z-reads, Purchasing
     | for the approvals. Nothing on the sign-in page changes when that
     | happens, which is the point of the registry.
     |
     | A key with no provider at all is skipped rather than throwing, so
     | removing a row from this list is how you take one off the screen.
     */
    'signin_state' => [
        'imports.loads.failed',
        'exceptions.open',
        'cash.zreads.unallocated',
        'purchasing.requests.awaiting',
    ],
];
