<?php

/*
|--------------------------------------------------------------------------
| Partners
|--------------------------------------------------------------------------
|
| Setup → Trading rules → Suppliers and Account customers (T024). Both
| masters are PumpIT's (BRN_Suppliers, BRN_Customers) and are READ here: no
| route saves, creates or deletes a partner, and nothing in this module writes
| PumpIT, so there is no write_mode switch. Where an edit would land is
| waiting on Ryan.
|
*/

return [

    'name' => 'Partners',

    // The palette's hits per search — usp_Partners_Search caps it at 50.
    'search_limit' => 12,

];
