{{--
    One site's place in a group.

    Three states, and the middle one is the point of the whole screen: a site
    that has not been previewed yet, a site that answered, and a site that
    REFUSED. On the live system twenty-four of twenty-six branches produce
    nothing and the screen never says why (finding 1) — so "nothing to
    reconcile here" and "this site has no rule configured" get different rows,
    different colours and different words.

    Rendered server-side both on first paint and as the fragment each preview
    returns, so the two cannot drift.

    Expects: $branch (Branch), $run (ReconRun|null), $group, $area.
--}}
@php($failed = $run && $run->Status === 'failed')
@php($ready = $run && $run->Status === 'previewed' && $run->MatchedRows > 0)
<tr data-group-branch="{{ $branch->BranchId }}"
    class="{{ $failed ? 'is-crit' : ($ready ? 'is-matched' : '') }}">
    <td class="pick">
        @if ($ready && ! $group->isCommitted())
            <input type="checkbox" name="runs[]" value="{{ $run->Id }}" data-check
                   aria-label="Post {{ $branch->Name }}" checked>
        @endif
    </td>
    <td>{{ $branch->Name }}</td>
    <td>
        @if (! $run)
            <span class="muted" data-group-pending>Waiting…</span>
        @elseif ($failed)
            <x-chip tone="crit">{{ $run->FailureCode }}</x-chip>
            <br><span class="drill-line-id">{{ $run->FailureMessage }}</span>
        @else
            <x-chip :tone="$run->Status === 'committed' ? 'good' : ($run->MatchedRows > 0 ? 'good' : 'neutral')">
                {{ $run->Status === 'committed' ? 'posted' : ($run->TotalRows === 0 ? 'nothing found' : $run->Status) }}
            </x-chip>
            <br><a class="drill-line-id" href="{{ route('app.recon.run', $run) }}">run #{{ $run->Id }}</a>
        @endif
    </td>
    <td class="num">{{ $run && ! $failed ? \App\Support\Format::n($run->TotalRows) : '—' }}</td>
    <td class="num">{{ $run && ! $failed ? \App\Support\Format::n($run->MatchedRows) : '—' }}</td>
    <td class="num">{{ $run && ! $failed ? \App\Support\Format::r($run->MatchedTotal) : '—' }}</td>
    <td class="num">{{ $run && ! $failed ? \App\Support\Format::n($run->BankOnlyRows) : '—' }}</td>
    <td class="num">{{ $run && ! $failed ? \App\Support\Format::n($run->DepositOnlyRows) : '—' }}</td>
    <td class="num muted">{{ $run && $run->CommittedRows ? \App\Support\Format::n($run->CommittedRows) : '—' }}</td>
</tr>
