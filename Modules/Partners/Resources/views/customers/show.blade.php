<x-app-shell :title="$customer->CustomerName ?? $customer->CustomerCode">
    <x-page-head eyebrow="Setup · Trading rules · Account customers" :title="$customer->CustomerName ?? $customer->CustomerCode"
                 :blurb="$customer->CustomerCode.' · '.$customer->BranchName">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.customers.index', ['branches' => [$customer->BranchId]]) }}">Back to account customers</a>
        </x-slot:actions>
    </x-page-head>
    @include('partners::customers.detail')
</x-app-shell>
