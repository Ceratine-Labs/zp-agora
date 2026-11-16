{{--
    Support requests raised on chains (Ryan, 16 Nov 2026): open first, newest
    first, for the sites this person may see. Each carries the chain as it was
    raised, the message, the marked-up screenshot, and who resolved it.
--}}
<x-app-shell title="Support requests" wide>
    <x-page-head compact eyebrow="Stock recon centre" title="Support requests"
                 blurb="Problems raised on a chain from a run's proposals, with the chain's details and a screenshot.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.stockrecon.index') }}">Back to the centre</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('resolved'))
        <x-notice tone="info" :title="session('resolved')" style="margin-bottom:12px" />
    @endif

    <x-card flush class="support-log">
        <span class="tt-pills-set" role="group" aria-label="Status" style="padding:10px 14px">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
                <a @class(['chip', 'tt-pill', 'is-on' => $status === $key]) @if ($status === $key) aria-current="true" @endif
                   href="{{ route('app.stockrecon.support.index', ['status' => $key]) }}">{{ $label }}<span class="tt-pill-n">{{ \App\Support\Format::n($key === 'all' ? $counts->sum() : ($counts[$key] ?? 0)) }}</span></a>
            @endforeach
        </span>
        <x-table :count="$tickets->count()" fit empty="No support requests here.">
            <x-slot:head>
                <tr>
                    <th class="num">#</th><th class="l">Raised</th><th class="l">Site · area · item</th>
                    <th class="l">Chain</th><th class="l">Message</th><th class="l">Screenshot</th><th class="l">Status</th><th></th>
                </tr>
            </x-slot:head>
            @foreach ($tickets as $t)
                <tr>
                    <td class="num">{{ $t->Id }}</td>
                    <td class="l">{{ \App\Support\BusinessDate::show($t->CreatedAt) }}<br><span class="muted">{{ $people[$t->CreatedBy] ?? '—' }}</span></td>
                    <td class="l">{{ $t->ItemDescription ?? $t->StockItemNo }}<br><span class="muted">{{ $t->StockItemNo }} · {{ $t->AreaDescription ?? 'area '.$t->AreaNo }} · site {{ $t->BranchId }}</span></td>
                    <td class="l"><a href="{{ route('app.stockrecon.run', $t->RunId) }}">Run #{{ $t->RunId }}</a><br><span class="muted">{{ $t->ChainFrom->format('j M') }} – {{ $t->ChainTo->format('j M Y') }}</span></td>
                    <td class="l"><div class="support-msg">{{ $t->Message }}</div></td>
                    <td class="l">
                        @if ($t->ScreenshotPath)
                            <a href="{{ route('app.stockrecon.support.screenshot', $t->Id) }}" target="_blank" rel="noopener">
                                <img class="support-thumb" src="{{ route('app.stockrecon.support.screenshot', $t->Id) }}" alt="Screenshot for request {{ $t->Id }}" loading="lazy">
                            </a>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="l">
                        <x-chip :tone="$t->isOpen() ? 'warn' : 'good'">{{ $t->isOpen() ? 'Open' : 'Resolved' }}</x-chip>
                        @unless ($t->isOpen())
                            <br><span class="muted">{{ $people[$t->ResolvedBy] ?? '' }}{{ $t->ResolutionNote ? ': '.$t->ResolutionNote : '' }}</span>
                        @endunless
                    </td>
                    <td class="fit-min">
                        @if ($t->isOpen())
                            @can('stockrecon.runs.execute')
                                <form method="POST" action="{{ route('app.stockrecon.support.resolve', $t->Id) }}"
                                      data-confirm="Mark request #{{ $t->Id }} resolved?" data-confirm-action="Resolve">
                                    @csrf
                                    <button type="submit" class="btn-ghost btn-row">Resolve</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-app-shell>
