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

    A fourth layer once the site is posted (12 Nov 2026): $post is its
    agora.ReconGroupPost row — queued, posting, refused or failed — written by
    the queued job and polled by the screen. A refused or failed post leaves
    the run a preview, so the site can be ticked and posted again.

    Expects: $branch (Branch), $run (ReconRun|null), $group, $area, and
    optionally $post (ReconGroupPost|null).
--}}
@php($post ??= null)
@php($failed = $run && $run->Status === 'failed')
@php($posting = $post && $post->isActive())
@php($postFailed = $post && in_array($post->Status, ['refused', 'failed'], true) && $run && $run->Status === 'previewed')
@php($ready = $run && $run->Status === 'previewed' && $run->MatchedRows > 0 && ! $posting)
<tr data-group-branch="{{ $branch->BranchId }}"
    @if ($post) data-group-post="{{ $post->Status }}" @endif
    class="{{ $failed || $postFailed ? 'is-crit' : ($ready ? 'is-matched' : '') }}">
    <td class="pick">
        @if ($ready)
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
        @elseif ($posting)
            <x-chip tone="neutral">{{ $post->Status === 'running' ? 'posting…' : 'queued' }}</x-chip>
            <br><a class="drill-line-id" href="{{ route('app.recon.run', $run) }}">run #{{ $run->Id }}</a>
        @elseif ($postFailed)
            <x-chip tone="crit">{{ $post->Code }}</x-chip>
            <br><span class="drill-line-id">{{ $post->Message }}</span>
            <br><a class="drill-line-id" href="{{ route('app.recon.run', $run) }}">run #{{ $run->Id }}</a>
        @else
            @if ($post && $post->isStale())
                <x-chip tone="warn">no answer since {{ ($post->StartedAt ?? $post->QueuedAt)?->format('H:i') }}</x-chip>
            @endif
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
