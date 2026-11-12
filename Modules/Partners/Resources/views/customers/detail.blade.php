@php
    use App\Support\Format;
    $yes = fn ($value) => $value ? 'Yes' : 'No';
    $code = trim((string) $customer->CustomerCode);
    $show = route('app.setup.customers.show', ['branch' => $customer->BranchId, 'code' => $code]);
    $statement = $customer->OnTill && $customer->AccountNo !== null
        ? route('app.debtors.show', ['branch' => $customer->BranchId, 'account' => $customer->AccountNo])
        : null;
@endphp
<x-card :title="$customer->CustomerName ?? $code" :sub="$code.' · '.$customer->BranchName">
    <x-slot:actions>
        @if (request()->ajax())<a class="btn sm" href="{{ $show }}">Open</a>@endif
        @if ($statement)
            @can('debtors.statements.view')<a class="btn sm" href="{{ $statement }}">Statement</a>@endcan
        @endif
    </x-slot:actions>
    <x-facts>
        <x-fact label="Kind" :value="$customer->Kind" :tone="$customer->IsPaymentControl ? 'warn' : null">
            {{ $customer->IsPaymentControl ? 'A payment-control account: how PumpIT books a method of payment, not a trading partner.' : 'A trading account at this site.' }}
        </x-fact>
        <x-fact label="Till account" :value="$customer->AccountNo" :tone="$customer->AccountNo !== null && ! $customer->OnTill ? 'warn' : null">
            {{ $customer->AccountNo === null ? 'PumpIT names no till account.' : ($customer->OnTill ? 'On the till\'s debtor master.' : 'The till has no such debtor account.') }}
        </x-fact>
        <x-fact label="Credit limit" :value="$customer->TillCreditLimit !== null ? Format::r($customer->TillCreditLimit) : null">
            The till's. PumpIT holds {{ $customer->CreditLimit !== null ? Format::r($customer->CreditLimit) : 'none' }}.
        </x-fact>
        <x-fact label="Cell" :value="$customer->TillCellNo">The till's{{ $customer->CellNo ? '; PumpIT holds '.$customer->CellNo : '; PumpIT holds none' }}.</x-fact>
        <x-fact label="Blocked at the till" :value="$customer->OnTill ? $yes($customer->TillIsBlocked) : null" :tone="$customer->TillIsBlocked ? 'crit' : null" />
        <x-fact label="Name at the till" :value="$customer->TillName" />
        <x-fact label="Contact" :value="$customer->TillContact ?? $customer->ContactPerson">{{ $customer->JobTitle }}</x-fact>
        <x-fact label="Telephone" :value="$customer->TillTelephone ?? $customer->Telephone" />
        <x-fact label="Statement to" :value="$customer->StatementEmail">
            {{ $customer->StatementEmail ? ($customer->StatementOnMonthlyRun ? 'On the monthly run.' : 'Not on the monthly run.') : 'No address yet.' }}
            @if ($statement)
                @can('debtors.contacts.edit') Changed on the debtor's statement. @endcan
            @endif
        </x-fact>
        @if ($customer->StatementCcEmail)
            <x-fact label="Copies to" :value="$customer->StatementCcEmail" />
        @endif
        <x-fact label="Group account" :value="$customer->GroupAccountNo" />
        <x-fact label="VAT number" :value="$customer->VatNo ?? $customer->TillVatNo" />
        <x-fact label="Address" :value="$customer->TillAddress ?: $customer->PhysicalAddress" />
        <x-fact label="Postal address" :value="$customer->PostalAddress" />
        <x-fact label="GL code" :value="$customer->GlCode" />
        <x-fact label="POS system" :value="$customer->PosSystemId" />
        <x-fact label="Loaded to accounts" :value="$yes($customer->IsSdkLoaded)" />
        <x-fact label="Created in PumpIT" :value="$customer->CreateDateTime ? \Carbon\Carbon::parse($customer->CreateDateTime)->format('j M Y') : null" />
        @if ($customer->Comments)
            <x-fact label="Comments" :value="$customer->Comments" />
        @endif
    </x-facts>
</x-card>
