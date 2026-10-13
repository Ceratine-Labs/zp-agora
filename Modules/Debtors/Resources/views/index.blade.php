{{--
    Debtors statements — every account with something to say for the period,
    per site, over agora.usp_Debtors_GridAccounts. A row opens its statement.

    Email selected: every account with an address starts ticked; the person
    unticks the ones they do not want and sends the rest for the period the
    grid shows. The ticks are this page's, which is why the grid pages at 200.

    Props: $grid, $mode (config debtors.send_mode), $testRecipient
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
        @can('debtors.statements.send')
            <x-slot:actions>
                <button type="button" class="btn-primary" data-modal-open="debtors-send-selected">Email selected</button>
            </x-slot:actions>
        @endcan
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

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

    @can('debtors.statements.send')
        <x-modal id="debtors-send-selected" title="Email the selected statements">
            {{-- data-bulk-form names the grid whose ticks this form carries;
                 bulk-selection.js keeps the hidden inputs and the count in step. --}}
            <form method="POST" action="{{ route('app.debtors.sendSelected') }}" data-bulk-form="app.debtors.index">
                @csrf
                <div data-bulk-items></div>

                <p style="margin-top:0">
                    <strong><span data-bulk-count>0</span> account(s)</strong> ticked on this page, each mailed its
                    statement for the period the list shows, as a PDF.
                </p>

                @if ($mode === 'off')
                    <x-notice tone="info" title="Sending is switched off">
                        Nothing can be mailed until sending is switched on.
                    </x-notice>
                @else
                    <p class="field-help">
                        @if ($mode === 'test')
                            Test mode: every one goes to {{ $testRecipient ?: 'the test address' }}, not to the debtor.
                        @else
                            They go to each debtor's address and copies. An account with no address is left out.
                        @endif
                        Untick any you do not want on the list behind this box.
                    </p>
                    <div class="btn-row">
                        <button type="submit" class="btn-primary">Send them</button>
                    </div>
                @endif
            </form>
        </x-modal>
    @endcan

</x-app-shell>
