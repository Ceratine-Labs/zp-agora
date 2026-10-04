{{--
    Staff shorts list (T040) — every staff short across sites, as one grid over
    agora.usp_Cash_GridStaffShorts: PumpIT's staff-shorts list and its approval
    queue together, waiting first. A row opens the short on its site's screen,
    where it is decided or posted.

    Props: $grid
--}}
<x-app-shell title="Staff shorts list">
    <x-page-head eyebrow="Cash" title="Staff shorts">
        <x-slot:info>
            <p>Every staff short, waiting first. <strong>Waiting</strong> shorts wait for an approver;
               <strong>Approved</strong> ones are owed but not charged until posted to a cashup; <strong>Posted</strong>
               ones are on a cashup's pump shortages; <strong>Declined</strong> ones are not charged. Waiting and
               approved shorts are listed whatever the dates.</p>
            <p>The reason is one of four codes. A short raised in PumpIT reads as the code its typed reason matches,
               and the text as typed is the note. A row opens the short on its site's screen.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.staffshorts') }}">Staff shorts</a>
        </x-slot:actions>
    </x-page-head>

    <x-data-grid :grid="$grid" surface />

</x-app-shell>
