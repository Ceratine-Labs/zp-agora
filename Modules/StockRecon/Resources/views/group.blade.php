<x-app-shell title="Stock preview group">
    <x-page-head eyebrow="Control · stock" :title="$group->Note ?: 'Stock preview group'">
        <x-slot:actions><a class="btn-ghost" href="{{ route('app.stockrecon.groups.form') }}">Every-site previews</a></x-slot:actions>
    </x-page-head>
    <x-card :title="$group->FromDate->toDateString().' to '.$group->ToDate->toDateString()" sub="Each site has its own result. Open a successful preview to review its proposals and decide what to commit.">
        <div data-stock-group data-auto="{{ session('group_auto') ? '1' : '0' }}">
            <p role="status" aria-live="polite" data-stock-group-status>{{ $results->whereNotIn('Status', ['pending', 'failed'])->count() }} of {{ $results->count() }} sites previewed; {{ $results->where('Status', 'failed')->count() }} failed.</p>
            @can('stockrecon.runs.create')
                @if ($results->where('Status', 'pending')->isNotEmpty())<button type="button" class="btn-primary" data-stock-group-start>Resume pending sites</button>@endif
                @if ($results->where('Status', 'failed')->isNotEmpty())<button type="button" class="btn-ghost" data-stock-group-retry>Retry failed sites</button>@endif
            @endcan
            @foreach ($results as $result)
                <x-card :title="$result->BranchName" :sub="$result->Status">
                    <div data-stock-group-site data-state="{{ $result->Status }}" data-name="{{ $result->BranchName }}" data-url="{{ route('app.stockrecon.groups.branch', [$group->GroupRef, $result->BranchId]) }}">
                        <p data-stock-group-result>{{ $result->FailureMessage ?: ($result->Status === 'pending' ? 'Waiting to preview.' : $result->TotalRows.' shifts; '.$result->AmendedRows.' proposed amendments; '.$result->BlockedChains.' blocked chains.') }}</p>
                        @if ($result->RunId && $result->Status !== 'failed')
                            <a class="btn-primary" href="{{ route('app.stockrecon.run', $result->RunId) }}">Review this site's run</a>
                        @elseif (in_array($result->Status, ['pending', 'failed']))
                            @can('stockrecon.runs.create')
                                <form method="POST" action="{{ route('app.stockrecon.groups.branch', [$group->GroupRef, $result->BranchId]) }}">@csrf<input type="hidden" name="retry" value="{{ $result->Status === 'failed' ? 1 : 0 }}"><button class="btn-ghost" type="submit">{{ $result->Status === 'failed' ? 'Retry this site' : 'Preview this site' }}</button></form>
                            @endcan
                        @endif
                    </div>
                </x-card>
            @endforeach
        </div>
    </x-card>
</x-app-shell>
