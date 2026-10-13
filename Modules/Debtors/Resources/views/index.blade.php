{{--
    Debtors statements — every account with something to say for the period,
    per site, over agora.usp_Debtors_GridAccounts. A row opens its statement.

    Props: $grid, $mode (config debtors.send_mode)
--}}
<x-app-shell title="Debtors statements">
    <x-page-head eyebrow="Money" title="Debtors statements">
        <x-slot:info>
            <p>One row per account per site, from the till's own debtors ledger: what was brought forward, what was
               charged and what was paid or credited in the period, and the balance a statement for it shows. A
               negative balance is money the debtor has paid ahead. The period defaults to last month.</p>
            <p>Accounts <strong>on the monthly run</strong> are mailed their statement for the month before on the
               1st. An account goes on the run from its statement, once it has an email address.</p>
            <p>The ledger PumpIT holds starts in June 2023 at most sites, later at some. An account older than that
               carries no take-on balance, so its balance is only what moved since.</p>
        </x-slot:info>
    </x-page-head>

    @error('refusal')
        <x-notice tone="warn" title="That was refused">{{ $message }}</x-notice>
    @enderror

    @if ($mode === 'off')
        <x-notice tone="info" title="Statements are not being mailed yet">
            Sending is switched off until the balances have been checked against the books. Statements can be
            opened, checked and downloaded; nothing goes to a debtor.
        </x-notice>
    @elseif ($mode === 'test')
        <x-notice tone="warn" title="Test mode">
            Every statement sent — by hand or by the monthly run — goes to the test address, not to the debtor.
        </x-notice>
    @endif

    <x-data-grid :grid="$grid" surface />

</x-app-shell>
