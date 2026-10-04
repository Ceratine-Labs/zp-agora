{{--
    One Z-read's panel as a page — for anybody who opened the row's address
    directly rather than expanding it in the grid. Same markup, inside the
    shell, with the way back.
--}}
<x-app-shell title="Z-read {{ $zread->LogFile }}">
    <x-page-head eyebrow="Cash · ZREAD Allocation"
                 title="Z-read {{ $zread->Location }} {{ $zread->TerminalNo }} · {{ $zread->LogFile }}">
        <x-slot:info>
            <p>One Z-read, what the system proposes for it, and why — beside who normally runs its terminal. Accept
               and Set only stage it; Update ZREAD, or Save just this one, saves it.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.zread', $scope->query()) }}">Back to ZREAD Allocation</a>
        </x-slot:actions>
    </x-page-head>

    <x-card>
        @include('cash::zread.partials.row')
    </x-card>
</x-app-shell>
