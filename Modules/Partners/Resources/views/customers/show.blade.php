<x-app-shell :title="$customer->CustomerName ?? $customer->CustomerCode">
    <x-page-head eyebrow="Setup · Trading rules · Account customers" :title="$customer->CustomerName ?? $customer->CustomerCode"
                 :blurb="$customer->CustomerCode.' · '.$customer->BranchName">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.customers.index', ['branches' => [$customer->BranchId]]) }}">Back to account customers</a>
            @if (! $customer->IsPaymentControl && \Modules\Partners\Services\PartnerWriteService::may('customers', 'edit'))
                <a class="btn-primary" href="{{ route('app.setup.customers.edit', ['branch' => $customer->BranchId, 'code' => trim((string) $customer->CustomerCode)]) }}">Edit customer</a>
            @endif
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not put back">{{ $message }}</x-notice>@enderror
    @include('partners::customers.detail')
    @include('partners::partials.changes', [
        'canReverse' => \Modules\Partners\Services\PartnerWriteService::may('customers', 'edit'),
        'reverseRoute' => 'app.setup.customers.changes.reverse',
        'routeParams' => ['branch' => $customer->BranchId, 'code' => trim((string) $customer->CustomerCode)],
    ])
</x-app-shell>
