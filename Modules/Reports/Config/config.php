<?php

/*
|--------------------------------------------------------------------------
| Reports — the Today menu, as fifteen procedure-backed reports
|--------------------------------------------------------------------------
|
| One entry per item on the Today menu. Each names the procedure that answers
| it, the columns it returns, and where it hangs in the navigation.
|
| This is MACHINERY, not exposure (feature-rules §2): nothing here is a figure
| the customer reads, so it does not have to come out of T-SQL. What they read
| comes out of `procedure`, and the screen puts that name on the grid so they
| can open it in SSMS and change it (§3.4).
|
| Every procedure takes the same eight parameters and returns page rows and
| a total. My queue also returns per-type full-scope totals as a third result
| set. Presentation differences are declared here.
|
| Column definitions:
|   key    the column in the result set
|   label  the header
|   type   text | number | money | litres | date | datetime | bool | chip
|          — decides the alignment, the format and, once T014 lands, the type
|          of the header filter (§3.1)
|   sort   the value to pass as @SortColumn; absent means the column is not
|          sortable, because the procedure has no sort key for it
|
| Scope metadata mirrors the configured procedure's date defaults so controls
| and links show the scope actually used. lookback_days is subtracted from To
| (both dates are inclusive); month_start uses the first of To's month.
| Explicit dates still reach SQL unchanged, including its range refusals.
|
*/

return [

    'name' => 'Reports',

    /*
    | The grid's page size, and the ceiling above which an export is refused and
    | sent to the export centre (feature-rules §3.2).
    */
    'page_size' => 50,
    'export_ceiling' => 100000,

    'reports' => [

        /* ---------------------------------------------------------- The day */

        'my-queue' => [
            'label' => 'My queue',
            'summary' => 'Outstanding work by site, with counts, age and value.',
            'group' => 'The day',
            'menu' => 'the-day/my-queue',
            'procedure' => 'usp_Reports_GridMyQueue',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 90,
                'help' => 'Defaults to today with a 90-day lookback. Dates limit the records shown; their outstanding status is current.',
                'search_placeholder' => 'Site or queue name…',
            ],
            'blurb' => 'Every queue with something outstanding in it, by site. A directory of the '
                .'other reports, not a second opinion — the counts are the same rules those reports apply.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'Queue', 'label' => 'Queue', 'type' => 'text', 'sort' => 'Queue'],
                ['key' => 'OpenItems', 'label' => 'Open', 'type' => 'number', 'sort' => 'OpenItems'],
                ['key' => 'OldestDate', 'label' => 'Oldest', 'type' => 'date', 'sort' => 'OldestDate'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
                ['key' => 'Value', 'label' => 'Value', 'type' => 'money', 'sort' => 'Value'],
            ],
        ],

        'day-close' => [
            'label' => 'Day close status',
            'summary' => 'Sites still open and what is delaying each close.',
            'group' => 'The day',
            'menu' => 'the-day/day-close',
            'procedure' => 'usp_Reports_GridDayClose',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 6,
                'help' => 'Defaults to seven calendar days through today. From and To may be at most 92 days apart.',
                'search_placeholder' => 'Site or EOD number…',
            ],
            'blurb' => 'Which sites have finished the day, which are still open, and what is holding '
                .'each one up. Built from every active site, not from the ledger — a site that never '
                .'started its close has no ledger row and would otherwise be invisible.',
            'was' => ['Day End Summary Comprehensive'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'ReconDate', 'label' => 'Trading day', 'type' => 'date', 'sort' => 'ReconDate'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
                ['key' => 'HoldingUp', 'label' => 'Holding it up', 'type' => 'text', 'sort' => 'HoldingUp'],
                ['key' => 'StepsDone', 'label' => 'Steps done', 'type' => 'number', 'sort' => 'StepsDone'],
                ['key' => 'ReconsOutstanding', 'label' => 'Recons open', 'type' => 'number', 'sort' => 'ReconsOutstanding'],
                ['key' => 'Cashups', 'label' => 'Cashups', 'type' => 'number', 'sort' => 'Cashups'],
                ['key' => 'DayEnds', 'label' => 'Day ends', 'type' => 'number'],
                ['key' => 'CashierShort', 'label' => 'Cashier short', 'type' => 'money', 'sort' => 'CashierShort'],
                ['key' => 'PumpShort', 'label' => 'Pump short', 'type' => 'money', 'sort' => 'PumpShort'],
                ['key' => 'TotalAmount', 'label' => 'Total', 'type' => 'money', 'sort' => 'TotalAmount'],
                ['key' => 'EODNo', 'label' => 'EOD no', 'type' => 'text', 'sort' => 'EODNo'],
            ],
        ],

        'overnight-loads' => [
            'label' => 'Overnight loads',
            'summary' => 'Overnight feed results by site, including feeds that never ran.',
            'group' => 'The day',
            'menu' => 'the-day/overnight-loads',
            'procedure' => 'usp_Reports_GridOvernightLoads',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 0,
                'help' => 'Defaults to today. From and To may be at most 92 days apart.',
                'search_placeholder' => 'Site or feed…',
            ],
            'blurb' => 'What landed last night, by site and feed. A feed that did not run shows as a '
                .'row saying zero rather than as an absence nobody notices.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'LoadDate', 'label' => 'Trading day', 'type' => 'date', 'sort' => 'LoadDate'],
                ['key' => 'Feed', 'label' => 'Feed', 'type' => 'text', 'sort' => 'Feed'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
                ['key' => 'RowsLoaded', 'label' => 'Rows', 'type' => 'number', 'sort' => 'RowsLoaded'],
                ['key' => 'LastRowAt', 'label' => 'Last row', 'type' => 'datetime', 'sort' => 'LastRowAt'],
                ['key' => 'FeedLastStampedAt', 'label' => 'Loader stamp', 'type' => 'datetime', 'sort' => 'FeedLastStampedAt'],
            ],
        ],

        'unallocated-zreads' => [
            'label' => 'Unallocated Z-reads',
            'summary' => 'Till readings still missing a shift or staff assignment.',
            'group' => 'The day',
            'menu' => 'the-day/unallocated-zreads',
            'procedure' => 'usp_Reports_GridUnallocatedZReads',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 90,
                'help' => 'Defaults to today with a 90-day lookback. Allocation status is current.',
                'search_placeholder' => 'Site, location, terminal or log file…',
            ],
            'blurb' => 'Till readings the system has, that nobody has claimed a shift or a person for.',
            'was' => ['ZReadings Not Allocated'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'ZReadDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'ZReadDate'],
                ['key' => 'Location', 'label' => 'Location', 'type' => 'text', 'sort' => 'Location'],
                ['key' => 'EodCounter', 'label' => 'EOD', 'type' => 'text', 'sort' => 'EodCounter'],
                ['key' => 'TerminalNo', 'label' => 'Terminal', 'type' => 'text', 'sort' => 'TerminalNo'],
                ['key' => 'LogFile', 'label' => 'Log file', 'type' => 'text', 'sort' => 'LogFile'],
                ['key' => 'Sales', 'label' => 'Sales', 'type' => 'money', 'sort' => 'Sales'],
                ['key' => 'Clue', 'label' => 'Clue', 'type' => 'text'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
            ],
        ],

        'open-cashups' => [
            'label' => 'Open cashups',
            'summary' => 'Cashups still open, with variances and reasons for delay.',
            'group' => 'The day',
            'menu' => 'the-day/open-cashups',
            'procedure' => 'usp_Reports_GridOpenCashups',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 90,
                'help' => 'Defaults to today with a 90-day lookback. Cashup closure status is current.',
                'search_placeholder' => 'Site, cashier or day-end number…',
            ],
            'blurb' => 'Cashups nobody has signed off — not closed by a manager in Agora, and not on a day PumpIT '
                .'has balanced.',
            'was' => ['Cashups Not Closed 2024 FY', 'Cashups Not Closed 2025 FY', 'Cashups Not Closed 2026 FY'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'ShiftDescription', 'label' => 'Shift', 'type' => 'text'],
                ['key' => 'TillDescription', 'label' => 'Till', 'type' => 'text', 'sort' => 'TillNo'],
                ['key' => 'EmployeeName', 'label' => 'Cashier', 'type' => 'text', 'sort' => 'EmployeeName'],
                ['key' => 'ZReading', 'label' => 'Z read', 'type' => 'money', 'sort' => 'ZReading'],
                ['key' => 'Variance', 'label' => 'Variance', 'type' => 'money', 'sort' => 'Variance'],
                ['key' => 'Status', 'label' => 'Cashup', 'type' => 'chip'],
                ['key' => 'HoldingUp', 'label' => 'Holding it up', 'type' => 'text', 'sort' => 'HoldingUp'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
            ],
        ],

        /* ------------------------------------------ Waiting on a decision */

        'purchase-approvals' => [
            'label' => 'Purchase approvals',
            'summary' => 'Pending purchase requests, totals and the people needed to approve.',
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/purchase-approvals',
            'procedure' => 'usp_Reports_GridPurchaseApprovals',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 90,
                'help' => 'Defaults to today with a 90-day lookback. Approval status is current.',
                'search_placeholder' => 'Site, supplier, request or description…',
            ],
            'blurb' => 'Purchase requests still waiting on somebody. One row per request with its line '
                .'total — the approver is a property of the expense code on each line, so a request '
                .'with two expense codes is waiting on two people.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionNo', 'label' => 'Request', 'type' => 'text', 'sort' => 'TransactionNo'],
                ['key' => 'PurchaseRequestDate', 'label' => 'Raised', 'type' => 'date', 'sort' => 'PurchaseRequestDate'],
                ['key' => 'SupplierName', 'label' => 'Supplier', 'type' => 'text', 'sort' => 'SupplierName'],
                ['key' => 'Description', 'label' => 'For', 'type' => 'text', 'sort' => 'Description'],
                ['key' => 'Lines', 'label' => 'Lines', 'type' => 'number', 'sort' => 'Lines'],
                ['key' => 'TotalAmount', 'label' => 'Total', 'type' => 'money', 'sort' => 'TotalAmount'],
                ['key' => 'WaitingOn', 'label' => 'Waiting on', 'type' => 'text', 'sort' => 'WaitingOn'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
            ],
        ],

        'staff-shorts' => [
            'label' => 'Staff shorts',
            'summary' => 'Employee cash shorts awaiting an approval or decline.',
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/staff-shorts',
            'procedure' => 'usp_Reports_GridStaffShorts',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 90,
                'help' => 'Defaults to today with a 90-day lookback. Approval status is current.',
                'search_placeholder' => 'Site, employee or reason…',
            ],
            'blurb' => 'Cash shorts raised against an employee that nobody has approved or declined. '
                .'Until one is decided it is neither a deduction nor a write-off.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'StaffShortsNo', 'label' => 'No', 'type' => 'number', 'sort' => 'StaffShortsNo'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'EmployeeName', 'label' => 'Employee', 'type' => 'text', 'sort' => 'EmployeeName'],
                ['key' => 'AmountShort', 'label' => 'Short', 'type' => 'money', 'sort' => 'AmountShort'],
                ['key' => 'Reason', 'label' => 'Reason', 'type' => 'text', 'sort' => 'Reason'],
                ['key' => 'Tills', 'label' => 'Tills', 'type' => 'text'],
                ['key' => 'CapturedBy', 'label' => 'Captured by', 'type' => 'text', 'sort' => 'CapturedBy'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
            ],
        ],

        'drop-safe' => [
            'label' => 'Drop safe collections',
            'summary' => 'Security collections compared with till drops, including uncollected bags.',
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/drop-safe',
            'procedure' => 'usp_Reports_GridDropSafe',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 30,
                'help' => 'Defaults to today with a 30-day lookback, by collection or drop date.',
                'search_placeholder' => 'Site, reference, collector or cashier…',
            ],
            'blurb' => 'What the security company took, against what the tills dropped — and, in the '
                .'same grid, the bags that were dropped and never collected at all.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'RowType', 'label' => 'Row', 'type' => 'chip', 'sort' => 'RowType'],
                ['key' => 'EventDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'EventDate'],
                ['key' => 'EventTime', 'label' => 'Time', 'type' => 'text'],
                ['key' => 'Reference', 'label' => 'Reference', 'type' => 'text', 'sort' => 'Reference'],
                ['key' => 'DeclaredAmount', 'label' => 'Declared', 'type' => 'money', 'sort' => 'DeclaredAmount'],
                ['key' => 'BagAmount', 'label' => 'In the bags', 'type' => 'money', 'sort' => 'BagAmount'],
                ['key' => 'AmountDifference', 'label' => 'Difference', 'type' => 'money', 'sort' => 'AmountDifference'],
                ['key' => 'BagCount', 'label' => 'Bags', 'type' => 'number', 'sort' => 'BagCount'],
                ['key' => 'SecurityName', 'label' => 'Collected by', 'type' => 'text', 'sort' => 'SecurityName'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'waste' => [
            'label' => 'Waste to approve',
            'menu_label' => 'Waste authorization',
            'summary' => 'Captured waste and operations authorizations by site and item.',
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/waste',
            'procedure' => 'usp_Reports_GridWaste',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 30,
                'help' => 'Defaults to today with a 30-day lookback, by captured waste date.',
                'search_placeholder' => 'Site, area or item…',
            ],
            'blurb' => 'Review captured waste and authorize the good quantity that may offset a shortage. '
                .'Agora records who authorized it; a changed quantity or capture time requires a new decision.',
            'was' => [],
            'caveat' => 'Only good waste authorized in Agora is applied to a shortage. Bad waste and unapproved quantities are not deducted.',
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'ShiftDescription', 'label' => 'Shift', 'type' => 'text'],
                ['key' => 'AreaDescription', 'label' => 'Area', 'type' => 'text', 'sort' => 'AreaDescription'],
                ['key' => 'StockItemDescription', 'label' => 'Item', 'type' => 'text', 'sort' => 'StockItemDescription'],
                ['key' => 'QtyGoodWaste', 'label' => 'Good', 'type' => 'number'],
                ['key' => 'AuthorizedGood', 'label' => 'Authorized good', 'type' => 'number'],
                ['key' => 'QtyBadWaste', 'label' => 'Bad', 'type' => 'number', 'sort' => 'QtyBadWaste'],
                ['key' => 'QtyTotalWaste', 'label' => 'Total qty', 'type' => 'number', 'sort' => 'QtyTotalWaste'],
                ['key' => 'TotalAtSellingPrice', 'label' => 'At selling price', 'type' => 'money', 'sort' => 'TotalAtSellingPrice'],
                ['key' => 'Status', 'label' => 'Authorization', 'type' => 'text'],
            ],
        ],

        /* ---------------------------------------------------------- Capture */

        'pump-readings' => [
            'label' => 'Pump readings',
            'summary' => 'Compare mechanical and electronic pump readings with POS sales.',
            'group' => 'Capture',
            'menu' => 'capture/pump-readings',
            'procedure' => 'usp_Reports_GridPumpReadings',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 6,
                'help' => 'Defaults to seven calendar days through today, by reading date.',
                'search_placeholder' => 'Site, pump or fuel grade…',
            ],
            'blurb' => 'The readings as captured, with what each implies in litres, and the three '
                .'figures that should agree: mechanical meter, electronic meter, and what the POS rang up.',
            'was' => ['Pump Readings date range', 'Pump Readings Capture', 'Pump Readings Sum per day'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'ReadingDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'ReadingDate'],
                ['key' => 'PumpNo', 'label' => 'Pump', 'type' => 'text', 'sort' => 'PumpNo'],
                ['key' => 'FuelTypeDescription', 'label' => 'Grade', 'type' => 'text', 'sort' => 'FuelTypeDescription'],
                ['key' => 'MechLitres', 'label' => 'Mechanical', 'type' => 'litres', 'sort' => 'MechLitres'],
                ['key' => 'ElecLitres', 'label' => 'Electronic', 'type' => 'litres', 'sort' => 'ElecLitres'],
                ['key' => 'POSSales', 'label' => 'POS', 'type' => 'litres', 'sort' => 'POSSales'],
                ['key' => 'MechVsPos', 'label' => 'Mech vs POS', 'type' => 'litres', 'sort' => 'MechVsPos'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'fuel-input' => [
            'label' => 'Fuel input',
            'summary' => 'Tank dips, deliveries and sales compared with pump totals.',
            'group' => 'Capture',
            'menu' => 'capture/fuel-input',
            'procedure' => 'usp_Reports_GridFuelInput',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 6,
                'help' => 'Defaults to seven calendar days through today. Earlier dips can supply the opening reading.',
                'search_placeholder' => 'Site or fuel grade…',
            ],
            'blurb' => 'The tank side of the forecourt: opening dip, deliveries, closing dip, what the '
                .'tank sold, and how that compares with the pumps. Valued at the cost price in force '
                .'on the day.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'FuelDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'FuelDate'],
                ['key' => 'FuelTypeDescription', 'label' => 'Grade', 'type' => 'text', 'sort' => 'FuelTypeDescription'],
                ['key' => 'OpeningDip', 'label' => 'Opening dip', 'type' => 'litres'],
                ['key' => 'Delivery', 'label' => 'Delivery', 'type' => 'litres', 'sort' => 'Delivery'],
                ['key' => 'ClosingDip', 'label' => 'Closing dip', 'type' => 'litres'],
                ['key' => 'TankSales', 'label' => 'Tank sold', 'type' => 'litres', 'sort' => 'TankSales'],
                ['key' => 'PumpSales', 'label' => 'Pumps sold', 'type' => 'litres', 'sort' => 'PumpSales'],
                ['key' => 'Variance', 'label' => 'Variance', 'type' => 'litres', 'sort' => 'Variance'],
                ['key' => 'VarianceAtCost', 'label' => 'At cost', 'type' => 'money', 'sort' => 'VarianceAtCost'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'daily-banking' => [
            'label' => 'Daily banking — manager\'s view',
            'menu_label' => 'Daily banking',
            'summary' => 'Cashup declarations, Z-reads, variances and current status by site.',
            'group' => 'Capture',
            // No `menu`: head office's Today → Capture → Daily banking opens the
            // Daily Banking screen (Cash, app.cash.banking), where a cashup is
            // opened, confirmed and closed. This is the same rows as a report in
            // the library, and Cash → Cashups is the same rows as a working grid.
            'procedure' => 'usp_Reports_GridDailyBanking',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 6,
                'help' => 'Defaults to seven calendar days through today, by cashup date.',
                'search_placeholder' => 'Site, cashier or day-end number…',
            ],
            'blurb' => 'Every cashup with its Z read, what was actually declared across its legs and the variance '
                .'— the same figures PumpIT\'s Daily Banking shows — and where it stands.',
            'was' => ['Daily Banking - Managers View'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'ShiftDescription', 'label' => 'Shift', 'type' => 'text'],
                ['key' => 'TillDescription', 'label' => 'Till', 'type' => 'text', 'sort' => 'TillNo'],
                ['key' => 'EmployeeName', 'label' => 'Cashier', 'type' => 'text', 'sort' => 'EmployeeName'],
                ['key' => 'ZReading', 'label' => 'Z read', 'type' => 'money', 'sort' => 'ZReading'],
                ['key' => 'ActualTotal', 'label' => 'Actual total', 'type' => 'money', 'sort' => 'ActualTotal'],
                ['key' => 'Variance', 'label' => 'Variance', 'type' => 'money', 'sort' => 'Variance'],
                ['key' => 'CashBags', 'label' => 'Cash bags', 'type' => 'money', 'sort' => 'CashBags'],
                ['key' => 'Cards', 'label' => 'Cards', 'type' => 'money', 'sort' => 'Cards'],
                ['key' => 'PumpShortages', 'label' => 'Pump shortages', 'type' => 'money', 'sort' => 'PumpShortages'],
                ['key' => 'ZreadCheck', 'label' => 'Z-read', 'type' => 'chip', 'sort' => 'ZreadCheck'],
                ['key' => 'Status', 'label' => 'Cashup', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'stock-count' => [
            'label' => 'Stock count',
            'summary' => 'Original stock counts, variances and the changes made by balancing.',
            'group' => 'Capture',
            'menu' => 'capture/stock-count',
            'procedure' => 'usp_Reports_GridStockCount',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 0,
                'help' => 'Defaults to today. From and To may be at most 92 days apart.',
                'search_placeholder' => 'Site, area or item…',
            ],
            'blurb' => 'The count itself: opening, issued, closing, what the computer expected, and the '
                .'variance. Read from the ORIGINAL captured quantities, with what balancing has amended '
                .'shown beside them.',
            'was' => ['Stock Recon Detail Variances', 'Stock Recon Detail Variances Only', 'Stock Variance Summary By Department'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'AreaDescription', 'label' => 'Area', 'type' => 'text', 'sort' => 'AreaDescription'],
                ['key' => 'StockItemDescription', 'label' => 'Item', 'type' => 'text', 'sort' => 'StockItemDescription'],
                ['key' => 'QtyOpen', 'label' => 'Opening', 'type' => 'number'],
                ['key' => 'QtyIssued', 'label' => 'Issued', 'type' => 'number'],
                ['key' => 'QtyClose', 'label' => 'Counted', 'type' => 'number', 'sort' => 'QtyClose'],
                ['key' => 'QtyComputer', 'label' => 'Expected', 'type' => 'number', 'sort' => 'QtyComputer'],
                ['key' => 'QtyVar', 'label' => 'Variance', 'type' => 'number', 'sort' => 'QtyVar'],
                ['key' => 'ValueVar', 'label' => 'Value', 'type' => 'money', 'sort' => 'ValueVar'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        /*
         | Owned by Utilities (T056) since 27 Sep 2026: the `Utility meter
         | readings` definition binds to the procedure behind the utilities
         | screen's own grid, so the library and the screen give one answer.
         | No `menu` key — Capture → Utility meters now opens the capture
         | screen itself (Modules/Utilities MenuSeeder). The report's old
         | procedure, usp_Reports_GridUtilityMeters, is still deployed and now
         | unused; retiring it belongs to the Reports procedures migration.
         */
        'utility-meters' => [
            'label' => 'Utility meter readings',
            'menu_label' => 'Utility readings',
            'summary' => 'Meter readings, prepaid units and calculated usage by site.',
            'group' => 'Capture',
            'procedure' => 'usp_Utilities_GridReadings',
            'scope' => [
                'date_mode' => 'range', 'month_start' => true,
                'help' => 'Defaults to month to date. Earlier readings can supply the opening reading.',
                'search_placeholder' => 'Site, meter or utility type…',
            ],
            'blurb' => 'Every meter reading by site and meter, with the usage each implies: the closing, less '
                .'the previous reading whenever it was taken, less prepaid units. A day PumpIT holds as 0 / 0 '
                .'has not been read and shows as No reading, with no usage. The same procedure as the grid on '
                .'the utilities screen.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'UtilityTypeDescription', 'label' => 'Type', 'type' => 'text', 'sort' => 'UtilityTypeDescription'],
                ['key' => 'MeterDescription', 'label' => 'Meter', 'type' => 'text', 'sort' => 'MeterDescription'],
                ['key' => 'OpeningReading', 'label' => 'Opening', 'type' => 'number'],
                ['key' => 'ClosingReading', 'label' => 'Closing', 'type' => 'number', 'sort' => 'ClosingReading'],
                ['key' => 'PrepaidUnitsPurchased', 'label' => 'Prepaid', 'type' => 'number'],
                ['key' => 'Usage', 'label' => 'Usage', 'type' => 'number', 'sort' => 'Usage'],
                ['key' => 'GapDays', 'label' => 'Gap (days)', 'type' => 'number', 'sort' => 'GapDays'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'import-pos' => [
            'label' => 'Import POS files',
            'menu_label' => 'POS imports',
            'summary' => 'POS import settings and the data received at each site.',
            'group' => 'Capture',
            'menu' => 'capture/import-pos',
            'procedure' => 'usp_Reports_GridImportPosFiles',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 0,
                'help' => 'Defaults to today. Dates limit received feed counts; import setup details remain current.',
                'search_placeholder' => 'Site, POS type, import type or drive…',
            ],
            'blurb' => 'What each site is configured to import, from which POS system and drive, and '
                .'whether that configuration produced anything. PumpIT keeps no per-file log — '
                .'BRN_POSImportedFiles is empty — so this is configuration against outcome.',
            'was' => [],
            'caveat' => 'BRN_POSImportedFiles is empty, so there is no file-by-file history in PumpIT to '
                .'report. A per-file log would be Agora\'s own.',
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'ConfiguredPosType', 'label' => 'POS', 'type' => 'text', 'sort' => 'ConfiguredPosType'],
                ['key' => 'POSImportType', 'label' => 'Imports', 'type' => 'text', 'sort' => 'POSImportType'],
                ['key' => 'ImportFromDrive', 'label' => 'Drive', 'type' => 'text'],
                ['key' => 'DayEnds', 'label' => 'Day ends', 'type' => 'number', 'sort' => 'DayEnds'],
                ['key' => 'ZReads', 'label' => 'Z-reads', 'type' => 'number', 'sort' => 'ZReads'],
                ['key' => 'BranchLastImportAt', 'label' => 'Loader stamp', 'type' => 'datetime', 'sort' => 'BranchLastImportAt'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        /* -------------------------------------------------------- Governance */

        /*
         | Every change Agora has made in PumpIT (Ryan, 1 Oct 2026: "save all
         | auto amendments we make … so we can refer back to it"). Read from
         | agora.vw_PumpItChange, which reads the ledgers the reversals run on.
         | No `menu` key: it hangs under Control → Governance → Audit trail
         | (AuditTrailMenuSeeder), not under Today. The procedure also returns
         | Detail — a capture's field-level before/after as JSON — which is left
         | off the grid because it runs to kilobytes; Ledger and Record name it.
         */
        'audit-trail' => [
            'label' => 'Audit trail',
            'summary' => 'Agora changes in PumpIT, with before/after values, people and reversals.',
            'group' => 'Governance',
            'procedure' => 'usp_Reports_GridAuditTrail',
            'scope' => [
                'date_mode' => 'range', 'lookback_days' => 7,
                'help' => 'Defaults to today with a seven-day lookback, by change date.',
                'search_placeholder' => 'Site, user, table or change…',
            ],
            // Click a row: the correction opened, the way the customer report
            // reads one (usp_Reports_GetAuditChange, 2 Oct 2026).
            'detail' => ['route' => 'app.reports.audit.change', 'params' => ['ledger' => 'Ledger', 'id' => 'LedgerId']],
            'blurb' => 'Every change Agora has made in PumpIT, newest first: stock recon counts, bank recon '
                .'stamps, Z-read allocations, utility readings and the captures. Each row says which PumpIT row '
                .'changed, from what to what, why, who pressed it and when, and whether it was reversed. '
                .'Times are SAST. The last 7 days unless you choose dates.',
            'was' => [],
            'columns' => [
                ['key' => 'ChangedAt', 'label' => 'When', 'type' => 'datetime', 'sort' => 'ChangedAt'],
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'Module', 'label' => 'Area', 'type' => 'text', 'sort' => 'Module'],
                ['key' => 'Action', 'label' => 'Action', 'type' => 'text', 'sort' => 'Action'],
                ['key' => 'RowKey', 'label' => 'Row changed', 'type' => 'text', 'sort' => 'RowKey'],
                ['key' => 'Change', 'label' => 'Before → after', 'type' => 'text'],
                ['key' => 'Reason', 'label' => 'Why', 'type' => 'text'],
                ['key' => 'ChangedBy', 'label' => 'By', 'type' => 'text', 'sort' => 'ChangedBy'],
                ['key' => 'State', 'label' => 'State', 'type' => 'chip', 'sort' => 'State'],
                ['key' => 'ReversedAt', 'label' => 'Reversed', 'type' => 'datetime', 'sort' => 'ReversedAt'],
                ['key' => 'ReversedBy', 'label' => 'Reversed by', 'type' => 'text'],
                ['key' => 'ReversalReason', 'label' => 'Reversal reason', 'type' => 'text'],
                ['key' => 'Ref', 'label' => 'Press', 'type' => 'text', 'sort' => 'Ref'],
                ['key' => 'PumpItTable', 'label' => 'PumpIT table', 'type' => 'text', 'sort' => 'PumpItTable'],
                ['key' => 'WriteMode', 'label' => 'Mode', 'type' => 'text'],
                ['key' => 'Ledger', 'label' => 'Ledger', 'type' => 'text'],
                ['key' => 'LedgerId', 'label' => 'Record', 'type' => 'number'],
            ],
        ],
    ],
];
