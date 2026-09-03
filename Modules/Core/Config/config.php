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
];
