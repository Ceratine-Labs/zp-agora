<?php

/*
|--------------------------------------------------------------------------
| Recon — the five AUTO RECON areas
|--------------------------------------------------------------------------
|
| One entry per reconciliation area, describing the procedure that previews
| it, the arguments it takes beyond branch and dates, and what its answer is
| called on screen. The five procedures do not return the same columns —
| ABSA has a merchant and a credit/debit split, SmartATM has a terminal and a
| trading window, CashBags works line by line rather than batch by batch — so
| the differences are declared here rather than branched on in a controller.
|
| This is machinery, not exposure: nothing here is a figure the customer
| reads, so it does not have to come out of a stored procedure (feature-rules
| §2). What the customer reads comes out of `procedure`, and the screen names
| that procedure so they can go and open it.
|
| The `legacy` key is the procedure this one replaces. It is on the screen for
| a reason — the whole conversation with ZP is about the difference between
| the two, and a number is easier to trust when the thing it is arguing with
| is named.
|
*/

return [

    'name' => 'Recon',

    /*
    | How far back a preview may look by default. A month is what the recon
    | clerk works in; the form allows anything.
    */
    'default_days' => 31,

    /*
    | Executing a reconciliation writes the stamp into the CUSTOMER'S estate.
    |
    | Deliberately a literal and NOT an environment variable. An env flag makes
    | "Agora starts writing to the customer's production database" a one-line
    | change somebody can make on a Friday; the rule it guards says that needs
    | an explicit decision on the record. Turning it on is meant to be a commit
    | with a name on it, reviewed like the write path it enables.
    |
    | 'journal' — a run is previewed, recorded and reviewable, and nothing in
    |             PumpIT moves. This is also the fallback ZP were offered in
    |             writing on 18 August 2026: keep a record of what Execute
    |             would reconcile from today onward.
    | 'live'    — the same run stamps ReconState and ReconBatchNo in PumpIT.
    |
    | SET TO 'live' ON 4 SEPTEMBER 2026 on Ryan's instruction ("lets enable the
    | execution please"). What that turns on, so the next person reading this
    | knows exactly what is now possible from a browser:
    |
    |   · agora.usp_Recon_Commit stamps RCN_BankStatementLinesPumpIT and the
    |     BRN_DailyBanking family in the customer's live database, and
    |     increments SS_UniqueNumber.
    |   · It acts ONLY on rows a recorded run marked WouldReconcile = 1 and an
    |     operator ticked, re-checks every one of them first, and skips
    |     anything that has moved.
    |   · Every stamp is recorded in agora.ReconMatch with the row's PRIOR
    |     state, so agora.usp_Recon_Reverse can put back precisely what was
    |     changed. The PumpIT executable can undo nothing.
    |
    | Two things were NOT settled when this was switched on, and both are ZP's
    | rather than ours — see docs/recon-execute.md:
    |   · they have not answered the 18 August 2026 note recommending they stop
    |     using their own Execute;
    |   · Agora reconciles CORRECTLY, which is a different reconciliation from
    |     the one their history was built with.
    */
    'stamp_mode' => 'live',

    'areas' => [

        'ABSA' => [
            'label' => 'ABSA card settlement',
            'procedure' => 'usp_Recon_PreviewABSA',
            'legacy' => 'sp_AUTOReconcile_ABSA_BankRecon',
            'blurb' => 'Card settlement batches against the takings declared at the till. '
                .'A batch settles on the sum of its credit and debit legs: both are shown, but only the net '
                .'decides the match.',
            'key_label' => 'Batch',
            'key2_label' => 'Merchant',
            'options' => ['RuleOrder'],
        ],

        'FNB' => [
            'label' => 'FNB card settlement',
            'procedure' => 'usp_Recon_PreviewFNB',
            'legacy' => 'sp_AUTOReconcile_FNB_BankRecon',
            'blurb' => 'Card settlement lines against the takings declared at the till. '
                .'More than half of the bank lines carry only a device id, with no batch or merchant number, '
                .'so no rule settles them: the Suggestions tab proposes pairings by value, and a clerk accepts '
                .'each one.',
            'key_label' => 'Batch',
            'key2_label' => 'Merchant',
            'options' => ['BatchKey', 'RuleOrder', 'MopsConvention', 'StandaloneRule', 'StandaloneLagDays'],
            // The Suggestions tab: agora.usp_Recon_SuggestMatches proposes
            // pairings by value for what the batch number could not pair.
            // ZP asked for it on 23 Sep 2026; a person accepts each one.
            'suggest' => true,
        ],

        'CashMachine' => [
            'label' => 'Cash machine',
            'procedure' => 'usp_Recon_PreviewCashMachine',
            'legacy' => 'sp_AUTOReconcile_CashMachine_BankRecon',
            'blurb' => 'Deposita slips against the bank. '
                .'Each site\'s rules are read from the configuration table, so every configured site can be previewed.',
            'key_label' => 'Slip',
            'key2_label' => null,
            'options' => ['RuleOrder', 'MopsConvention'],
        ],

        'CashBags' => [
            'label' => 'Cash bags',
            'procedure' => 'usp_Recon_PreviewCashBags',
            'legacy' => 'sp_AUTOReconcile_CashBags_BankRecon',
            'blurb' => 'Drop-safe bags against the bank deposit. '
                .'The bag reference is the collection\'s bag number, or the bag\'s own where there is none; '
                .'one found on more than one bank line is reported, not matched to either.',
            'key_label' => 'Bag',
            'key2_label' => null,
            'options' => ['MatchMode', 'RuleOrder', 'MopsConvention', 'MinBagKeyLen'],
        ],

        'SmartATM' => [
            'label' => 'Smart ATM',
            'procedure' => 'usp_Recon_PreviewSmartATM',
            'legacy' => 'sp_AUTOReconcile_SmartATM_BankRecon',
            'blurb' => 'Terminal deposits against the bank. '
                .'They pair within the trading day, midnight to midnight, of the date in the bank narrative.',
            'key_label' => 'Terminal',
            'key2_label' => null,
            'options' => ['RuleOrder', 'MopsConvention'],
        ],
    ],

    /*
    | The arguments beyond branch and dates.
    |
    | Every one of these exists because the customer's configuration is
    | ambiguous somewhere and we refused to guess on their behalf. They are on
    | the screen, not buried in a default, so that the answer a preview gives
    | is always traceable to the reading it was given — and so that when ZP
    | settles one of the open questions, the setting stops being a question
    | and becomes a default without anything else changing.
    */
    'options' => [

        'RuleOrder' => [
            'label' => 'Rule order',
            'help' => 'Which rule wins when a narrative matches more than one. Most specific prefers the '
                .'longest filter value; process order takes them as configured.',
            'default' => 'specific',
            'choices' => [
                'specific' => 'Most specific filter first',
                'processorder' => 'Configured process order',
            ],
        ],

        'MopsConvention' => [
            'label' => 'Deposit-side positions',
            'help' => 'How the deposit-side end position is read. It is a length in every area today.',
            'default' => 'length',
            'choices' => [
                'length' => 'End position is a length',
                'endpos' => 'End position is an end position',
            ],
        ],

        'BatchKey' => [
            'label' => 'FNB batch key',
            'help' => 'The FNB batch number is free text. Whole value compares it as a number; configured '
                .'slice compares only the configured part of it.',
            'default' => 'numeric',
            'choices' => [
                'numeric' => 'Whole value, as a number',
                'position' => 'Configured slice',
            ],
        ],

        'StandaloneRule' => [
            'label' => 'Test the standalone candidate rule',
            'help' => 'Standalone FNB lines never reconcile here, whatever this is set to. On, the preview '
                .'shows what the candidate rule would produce — per device, the bank day total against the '
                .'previous trading day\'s takings — for review.',
            'default' => 0,
            'type' => 'bool',
        ],

        'StandaloneLagDays' => [
            'label' => 'Standalone settlement lag (days)',
            'help' => 'How many days after the takings the bank settles. Next day on most batches.',
            'default' => 1,
            'type' => 'int',
            'min' => 0,
            'max' => 14,
        ],

        'MatchMode' => [
            'label' => 'Bag matching',
            'help' => 'Look for the bag reference anywhere in the bank narrative, as the live procedure does, '
                .'or only at the configured extraction positions.',
            'default' => 'contains',
            'choices' => [
                'contains' => 'Bag reference appears in the narrative',
                'position' => 'Configured extraction positions',
            ],
        ],

        'MinBagKeyLen' => [
            'label' => 'Shortest usable bag reference',
            'help' => 'A short reference matches unrelated narratives. A bag below this length is reported '
                .'but never matched.',
            'default' => 11,
            'type' => 'int',
            'min' => 4,
            'max' => 30,
        ],

        'BankStartOverride' => [
            'label' => 'Bank extraction start (override)',
            'help' => 'Try a different extraction without changing the customer\'s configuration. Needs the '
                .'length below as well.',
            'default' => null,
            'type' => 'int',
            'min' => 1,
            'max' => 200,
        ],

        'BankLenOverride' => [
            'label' => 'Bank extraction length (override)',
            'help' => 'The second half of the override above. Both are needed.',
            'default' => null,
            'type' => 'int',
            'min' => 1,
            'max' => 200,
        ],
    ],
];
