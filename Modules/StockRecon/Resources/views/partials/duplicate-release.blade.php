{{--
    The press that releases possible duplicate issues (D2) from holding their
    chain back — or, with `withdraw`, the press that takes one release back.
    Ryan, 5 Oct 2026, PM decision 01a10b21.

    One partial so every screen that shows a D2 offers the same press: the chain
    panel today, a duplicates panel next. The behaviour is
    resources/js/components/duplicate-release.js — a reason asked for in
    Agora's own dialog, one for the lot, then a POST — and the rules are the
    procedure's.

        @include('stockrecon::partials.duplicate-release', [
            'run'     => $run,
            'lines'   => [12, 13],            // run line ids on this run …
            // 'grid' => 'app.stockrecon.…',  // … or the ticked rows of a grid
            'label'   => 'Release both…',
            'summary' => 'Dunhill 10s, 17 Jul shifts 1 and 3, issue 20.',
        ])

        @include('stockrecon::partials.duplicate-release', [
            'run' => $run, 'withdraw' => $releaseId, 'summary' => '…',
        ])

    Rendered only for somebody who holds the grant — a control the user cannot
    use is not rendered (feature-rules §4). The route checks it again, and the
    procedure refuses what is not a D2.
--}}
@isset($withdraw)
    @can('stockrecon.duplicates.withdraw')
        <button type="button" class="btn-ghost btn-row"
                data-dup-withdraw="{{ route('app.stockrecon.duplicates.withdraw', ['run' => $run, 'release' => $withdraw]) }}"
                data-dup-summary="{{ $summary ?? '' }}">{{ $label ?? 'Withdraw release' }}</button>
    @endcan
@else
    @can('stockrecon.duplicates.release')
        <button type="button" class="btn-ghost btn-row"
                data-dup-release="{{ route('app.stockrecon.duplicates.release', $run) }}"
                @isset($grid) data-dup-grid="{{ $grid }}" @else data-dup-lines="{{ implode(',', (array) ($lines ?? [])) }}" @endisset
                data-dup-summary="{{ $summary ?? '' }}">{{ $label ?? 'Release…' }}</button>
    @endcan
@endisset
