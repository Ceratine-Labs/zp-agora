<?php

return [
    'cache_seconds' => 60,
    // Site groups are deliberately absent until the customer confirms membership.
    'screens' => [
        'fuel' => ['title' => 'Fuel', 'subtitle' => 'Tank movement and meter exceptions', 'sources' => ['fuel', 'meters', 'budget-fuel'], 'reports' => ['fuel-input', 'pump-readings'], 'gaps' => [
            'Cover uses the last seven complete captured days. Volume against last year is not connected.',
            'Attendant payment times are not connected; the sample 75 / 95 second warnings are not used.',
        ]],
        'shop' => ['title' => 'Shop', 'subtitle' => 'Yesterday’s turnover · current critical stock · daily budget', 'sources' => ['shop', 'critical', 'budget-shop'], 'reports' => ['day-close'], 'gaps' => [
            'KVI stock-on-hand uses the critical-lines list, including listed lubes. Days cover, item-sales gaps, expiry risk and historical price changes are not connected.',
            'Budget targets use calendar-day allocation where a monthly budget exists; recorded turnover comparisons use the same weekday, not budget.',
        ]],
        'qsr' => ['title' => 'QSR', 'subtitle' => 'Recorded fast-food turnover and unusual changes', 'sources' => ['qsr', 'budget-qsr'], 'reports' => ['day-close'], 'gaps' => [
            'Actual / theoretical GP, order times, extras sold and upselling are not connected.',
            'Wacky Wednesday bun / patty availability needs stock and recipe feeds; sales alone cannot prove a stockout.',
            'Food-safety checks and actions from site ratings are not connected.',
        ]],
        'stock' => ['title' => 'Stock', 'subtitle' => 'Top ten loss codes per site and import coverage', 'sources' => ['stock', 'loads'], 'reports' => ['stock-count'], 'gaps' => [
            'Action-pack progress and stock-take schedules are not connected.',
            'Losses include only authorized good waste. No employee blame is inferred from an item loss.',
        ]],
        'cashups' => ['title' => 'Cash-ups', 'subtitle' => 'Yesterday’s cash-ups and day-close status', 'sources' => ['cash'], 'reports' => ['day-close', 'open-cashups', 'daily-banking'], 'gaps' => [
            'Submission and review timestamps are not connected: the 10:00 / 11:00 deadlines cannot be confirmed from captured cash-ups.',
            'Card and EFT reconciliation ageing is available in the reconciliation reports, not yet on this wall.',
        ]],
    ],
];
