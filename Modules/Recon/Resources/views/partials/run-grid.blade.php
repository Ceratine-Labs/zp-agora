@php($canArchive = $grid->definition->selectable() && ! empty($archiveScope) && $grid->refusal === null)
<div class="recon-run-help">
    <span>Validity checks current bank rows matching each saved proposal.</span>
    <x-info label="How run validity is checked">
        <p><strong>Invalid</strong> means every proposal’s matching bank rows are already reconciled; counts and values still match the preview. These previews can be archived.</p>
        <p><strong>Partly superseded</strong> still has proposals to review. <strong>Current</strong> means the matching bank rows remain outstanding; executing still rechecks deposits and every other condition.</p>
        <p><strong>Needs review</strong> includes empty, changed or unverified previews. History is kept without a fresh validity check.</p>
    </x-info>
    @if ($canArchive && $grid->total > 0)
        <form method="POST" action="{{ route('app.recon.archive', ['ws' => app(\App\Support\BranchContext::class)->workspace()]) }}"
              data-confirm="Archive all {{ \App\Support\Format::n($grid->total) }} invalid runs in this filtered view?"
              data-confirm-text="This covers all pages using the displayed sites, period, person, search and column filters. Eligibility and access are checked again. The runs and their proposals stay on record, can be reopened, and nothing in PumpIT changes."
              data-confirm-action="Archive all filtered invalid runs" data-loader="Archiving invalid runs…">
            @csrf
            <input type="hidden" name="scope_token" value="{{ $archiveScope }}">
            <input type="hidden" name="mode" value="all">
            <button type="submit" class="btn-ghost sm">Archive all {{ \App\Support\Format::n($grid->total) }} invalid runs in this filtered view — all pages</button>
        </form>
    @endif
</div>

<x-data-grid :grid="$grid" :branches="$branches">
    @if ($canArchive)
        <x-slot:bulk>
            <button type="submit" form="archive-selected-runs" class="btn-ghost">Archive selected…</button>
        </x-slot:bulk>
    @endif
</x-data-grid>

@if ($canArchive)
    {{-- Keep the write form outside the grid's GET scope form. The existing
         bulk-selection component carries desktop or mobile ticks once. --}}
    <form id="archive-selected-runs" method="POST" action="{{ route('app.recon.archive', ['ws' => app(\App\Support\BranchContext::class)->workspace()]) }}"
          data-bulk-form="app.recon.runs"
          data-confirm="Archive the selected invalid runs?"
          data-confirm-text="Only the selected runs on this page are included. Eligibility and access are checked again. The runs and their proposals stay on record, can be reopened, and nothing in PumpIT changes."
          data-confirm-action="Archive selected runs" data-loader="Archiving selected runs…">
        @csrf
        <input type="hidden" name="scope_token" value="{{ $archiveScope }}">
        <input type="hidden" name="mode" value="selected">
        <div data-bulk-items></div>
    </form>
@endif
