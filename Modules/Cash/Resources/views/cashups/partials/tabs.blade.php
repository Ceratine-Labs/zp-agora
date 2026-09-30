{{--
    The cashup queues, as link-mode tabs: each is the same grid with a
    different `show`, or the Z-reads with no cashup, and each tab is a URL
    that carries the grid's own scope (branches, period) with it.

    Props: $active ('all' | 'open' | 'disagree' | 'nocashup')
--}}
@php
    $keep = collect(request()->query())->only(['branches', 'from', 'to'])->all();
@endphp
<x-tabs :active="$active" label="Cashup queues" style="margin-bottom:16px" :items="[
    ['key' => 'all', 'label' => 'All cashups', 'href' => route('app.cash.cashups', $keep)],
    ['key' => 'open', 'label' => 'Open cashups', 'href' => route('app.cash.cashups', $keep + ['show' => 'open'])],
    ['key' => 'disagree', 'label' => 'Z-read disagrees', 'href' => route('app.cash.cashups', $keep + ['show' => 'disagree'])],
    ['key' => 'nocashup', 'label' => 'Z-reads with no cashup', 'href' => route('app.cash.cashups.nocashup', $keep)],
]" />
