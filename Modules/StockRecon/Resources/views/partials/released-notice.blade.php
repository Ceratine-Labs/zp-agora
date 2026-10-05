{{--
    What a release, or a withdrawn release, of a possible duplicate issue did —
    the procedure's own sentence, which says to preview again: a release is read
    by the NEXT preview, and the run on screen still holds its chain back.
    On the run and on the chain page, the two places the press is made.
--}}
@if (session('released'))
    <x-notice tone="info" title="Possible duplicate issue" style="margin-bottom:16px">
        <p>{{ session('released') }}</p>
        <p><a href="{{ route('app.stockrecon.index', ['branch_id' => $run->BranchId, 'area_no' => $run->AreaNo,
                        'from' => $run->FromDate->toDateString(), 'to' => $run->ToDate->toDateString()]) }}">Preview
           this window again</a> to see it take effect.</p>
    </x-notice>
@endif
