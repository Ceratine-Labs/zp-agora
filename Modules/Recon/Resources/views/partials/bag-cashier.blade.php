{{--
    Under a cash bag in the drill: whose bag it was, and the line of the
    confirmed split its cash was accounted on.

    Only cash-bag rows carry CashierCode — usp_Recon_DrillMops and the
    ReconMatch read in ReconService::sides() both return it for CashBags and
    for nothing else — so on any other area this draws nothing. The split
    line is the CASHIER's (the same share on each of their bags), and it is
    there only when a batch with a split holds the bag now.

    Expects: $row
--}}
@if (property_exists($row, 'CashierCode'))
    <br><span class="drill-line-id">
        @if ($row->CashierCode === null)
            no cashier recorded
        @elseif ($row->CashierName === null)
            {{ $row->CashierCode }} · not on the employee list
        @else
            {{ $row->CashierName }} · {{ $row->CashierCode }}
        @endif
        @if (($row->AllocationShortStatus ?? null) !== null)
            · batch {{ $row->AllocationBatchNo }} split: their share {{ \App\Support\Format::r($row->AllocationShare) }},
            {{ \Modules\Recon\Support\StaffShort::words($row->AllocationShortStatus, $row->AllocationShare, $row->AllocationStaffShortsNo) }}
        @endif
    </span>
@endif
