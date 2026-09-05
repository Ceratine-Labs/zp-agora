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
| Every procedure takes the same eight parameters and returns the same two
| result sets, so the grid does not branch on which report it is showing. What
| differs is declared here.
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
            'group' => 'The day',
            'menu' => 'the-day/my-queue',
            'procedure' => 'usp_Reports_GridMyQueue',
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
            'group' => 'The day',
            'menu' => 'the-day/day-close',
            'procedure' => 'usp_Reports_GridDayClose',
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
            'group' => 'The day',
            'menu' => 'the-day/overnight-loads',
            'procedure' => 'usp_Reports_GridOvernightLoads',
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
            'group' => 'The day',
            'menu' => 'the-day/unallocated-zreads',
            'procedure' => 'usp_Reports_GridUnallocatedZReads',
            'blurb' => 'Till readings the system has, that nobody has claimed a shift or a person for.',
            'was' => ['ZReadings Not Allocated'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'ZReadDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'ZReadDate'],
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
            'group' => 'The day',
            'menu' => 'the-day/open-cashups',
            'procedure' => 'usp_Reports_GridOpenCashups',
            'blurb' => 'Cashups on a trading day that has never been balanced. Replaces the three '
                .'"Cashups Not Closed <FY>" reports with one date range.',
            'was' => ['Cashups Not Closed 2024 FY', 'Cashups Not Closed 2025 FY', 'Cashups Not Closed 2026 FY'],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'ShiftDescription', 'label' => 'Shift', 'type' => 'text'],
                ['key' => 'TillDescription', 'label' => 'Till', 'type' => 'text', 'sort' => 'TillNo'],
                ['key' => 'EmployeeName', 'label' => 'Cashier', 'type' => 'text', 'sort' => 'EmployeeName'],
                ['key' => 'ZReading', 'label' => 'Z-reading', 'type' => 'money', 'sort' => 'ZReading'],
                ['key' => 'Variance', 'label' => 'Variance', 'type' => 'money', 'sort' => 'Variance'],
                ['key' => 'HoldingUp', 'label' => 'Holding it up', 'type' => 'text', 'sort' => 'HoldingUp'],
                ['key' => 'AgeDays', 'label' => 'Age (days)', 'type' => 'number', 'sort' => 'AgeDays'],
            ],
        ],

        /* ------------------------------------------ Waiting on a decision */

        'purchase-approvals' => [
            'label' => 'Purchase approvals',
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/purchase-approvals',
            'procedure' => 'usp_Reports_GridPurchaseApprovals',
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
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/staff-shorts',
            'procedure' => 'usp_Reports_GridStaffShorts',
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
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/drop-safe',
            'procedure' => 'usp_Reports_GridDropSafe',
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
            'group' => 'Waiting on a decision',
            'menu' => 'decisions/waste',
            'procedure' => 'usp_Reports_GridWaste',
            'blurb' => 'Waste captured at a branch, by area, shift and item, with what it would have '
                .'sold for. PumpIT records no approval state for waste, so this is captured waste, not '
                .'a queue — see the procedure header.',
            'was' => [],
            'caveat' => 'PumpIT has no approval columns on STK_StockWasteLine, so there is no approval '
                .'queue to report. An approval trail for waste would be Agora\'s own.',
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'AreaDescription', 'label' => 'Area', 'type' => 'text', 'sort' => 'AreaDescription'],
                ['key' => 'StockItemDescription', 'label' => 'Item', 'type' => 'text', 'sort' => 'StockItemDescription'],
                ['key' => 'QtyGoodWaste', 'label' => 'Good', 'type' => 'number'],
                ['key' => 'QtyBadWaste', 'label' => 'Bad', 'type' => 'number', 'sort' => 'QtyBadWaste'],
                ['key' => 'QtyTotalWaste', 'label' => 'Total qty', 'type' => 'number', 'sort' => 'QtyTotalWaste'],
                ['key' => 'TotalAtSellingPrice', 'label' => 'At selling price', 'type' => 'money', 'sort' => 'TotalAtSellingPrice'],
            ],
        ],

        /* ---------------------------------------------------------- Capture */

        'pump-readings' => [
            'label' => 'Pump readings',
            'group' => 'Capture',
            'menu' => 'capture/pump-readings',
            'procedure' => 'usp_Reports_GridPumpReadings',
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
            'group' => 'Capture',
            'menu' => 'capture/fuel-input',
            'procedure' => 'usp_Reports_GridFuelInput',
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
            'label' => 'Daily banking',
            'group' => 'Capture',
            'menu' => 'capture/daily-banking',
            'procedure' => 'usp_Reports_GridDailyBanking',
            'blurb' => 'The daily banking as each till declared it: the Z-reading against the legs it '
                .'was made up of, and the variance the cashier carried.',
            'was' => [],
            'columns' => [
                ['key' => 'BranchName', 'label' => 'Site', 'type' => 'text', 'sort' => 'BranchName'],
                ['key' => 'TransactionDate', 'label' => 'Date', 'type' => 'date', 'sort' => 'TransactionDate'],
                ['key' => 'ShiftDescription', 'label' => 'Shift', 'type' => 'text'],
                ['key' => 'TillDescription', 'label' => 'Till', 'type' => 'text', 'sort' => 'TillNo'],
                ['key' => 'EmployeeName', 'label' => 'Cashier', 'type' => 'text', 'sort' => 'EmployeeName'],
                ['key' => 'ZReading', 'label' => 'Z-reading', 'type' => 'money', 'sort' => 'ZReading'],
                ['key' => 'DeclaredLegs', 'label' => 'Declared', 'type' => 'money', 'sort' => 'DeclaredLegs'],
                ['key' => 'DeclaredVsZ', 'label' => 'Declared vs Z', 'type' => 'money', 'sort' => 'DeclaredVsZ'],
                ['key' => 'CashierVariance', 'label' => 'Cashier variance', 'type' => 'money', 'sort' => 'CashierVariance'],
                ['key' => 'AttendantShort', 'label' => 'Attendant short', 'type' => 'money', 'sort' => 'AttendantShort'],
                ['key' => 'Status', 'label' => 'Status', 'type' => 'chip', 'sort' => 'Status'],
            ],
        ],

        'stock-count' => [
            'label' => 'Stock count',
            'group' => 'Capture',
            'menu' => 'capture/stock-count',
            'procedure' => 'usp_Reports_GridStockCount',
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

        'utility-meters' => [
            'label' => 'Utility meters',
            'group' => 'Capture',
            'menu' => 'capture/utility-meters',
            'procedure' => 'usp_Reports_GridUtilityMeters',
            'blurb' => 'Meter readings by site and meter, with the usage each implies. Usage is derived '
                .'here rather than read — the legacy screen rewrites it with an UPDATE, and Agora never '
                .'writes to PumpIT.',
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
            'group' => 'Capture',
            'menu' => 'capture/import-pos',
            'procedure' => 'usp_Reports_GridImportPosFiles',
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
    ],
];
