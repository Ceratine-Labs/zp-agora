<?php

/*
|--------------------------------------------------------------------------
| Debtors — statements of account (13 Oct 2026)
|--------------------------------------------------------------------------
|
| Statements are read from the customer's POS ledger and mailed to the
| addresses held in agora.DebtorContact. Nothing here is written to PumpIT.
|
*/

return [

    'name' => 'Debtors',

    /*
    | Whether a statement reaches a DEBTOR.
    |
    | 'off'   nothing is mailed. The screen, the PDF and the download all work;
    |         Send is not offered and the monthly run does nothing.
    | 'test'  every statement — a press of Send and the monthly run alike — goes
    |         to `test_recipient` instead of the debtor, with the debtor's
    |         address in the subject. For the customer to check statements
    |         before anybody outside the business sees one.
    | 'live'  statements go to the debtor's address, and its copies.
    |
    | A literal, never an env var, like every switch in Agora that reaches
    | outside it: mailing a business's customers is a commit with a name on it.
    |
    | 'off' until the customer has checked the balances. The ledger PumpIT
    | holds starts in June 2023 at most sites, so an account older than that
    | has no take-on balance in it, and a statement saying a debtor owes what
    | they do not would go to that debtor.
    */
    'send_mode' => 'off',

    /*
    | Where 'test' mode sends. Empty sends nothing in test mode.
    */
    'test_recipient' => '',

    /*
    | Copied on every LIVE statement — head office's own record of what went.
    | Empty for none.
    */
    'bcc' => '',

    /*
    | The monthly run (debtors:send-statements, routes/console.php): which day
    | of the month it runs, for the month before. Statements go to accounts
    | on the monthly run with something to say for that month.
    */
    'run_day' => 1,

    /*
    | Printed at the foot of every statement. The customer's wording; until
    | they give it, the plain one.
    */
    'remittance_note' => 'Please quote your account number with every payment. Queries on this statement to the site that issued it.',

];
