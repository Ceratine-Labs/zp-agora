{{-- The hub and area use the same scope switches, validity cells and archive forms. --}}
@php($mine = \Modules\Recon\Grids\ReconRunGrid::wantsOwnRunsOnly())
@php($openOnly = \Modules\Recon\Grids\ReconRunGrid::wantsOpenOnly())
@php($invalidOnly = \Modules\Recon\Grids\ReconRunGrid::wantsInvalidOnly())
@php($site = app(\App\Support\BranchContext::class)->id())

@include('recon::partials.run-archive-outcome')

<x-card title="Runs" class="recon-run-list"
        :sub="($invalidOnly ? 'Invalid runs — ' : ($openOnly ? 'Open work — ' : 'Every run — '))
              .($mine ? 'the previews you have made in this area' : 'everyone\'s, in this area')"
        flush>
    <x-slot:actions>
        @include('recon::partials.run-switches')
    </x-slot:actions>

    {{-- Clear previews, scoped the way the list above it is scoped: this area,
         this site, and — on Mine — only your own runs. The old hub button
         swept all five areas at once and everybody's with them, and a clerk
         who wanted to bin July but keep this week could not. A sweep never
         takes a run marked complete, nor anything executed. --}}
    @if ($site !== null && auth()->user()?->can('recon.runs.delete'))
        <form method="POST" action="{{ route('app.recon.clear') }}" class="dg-bar" style="margin:12px 14px 0"
              data-confirm="Clear these previews?"
              data-confirm-text="{{ $area['label'] }} previews on this site{{ $mine ? ' that you made' : ', whoever made them' }}, of the age you chose. Runs marked complete, and anything that has been executed, are kept. Nothing in PumpIT is affected — a preview is a record of a read."
              data-confirm-action="Clear previews" data-confirm-danger>
            @csrf
            @method('DELETE')
            <input type="hidden" name="area" value="{{ $area['key'] }}">
            @if ($mine)<input type="hidden" name="mine" value="1">@endif
            <label for="f-older-than-days" class="dg-shown">Clear {{ $mine ? 'my' : 'all' }} previews</label>
            <select id="f-older-than-days" name="older_than_days">
                <option value="7">older than 7 days</option>
                <option value="14" selected>older than 14 days</option>
                <option value="30">older than 30 days</option>
                <option value="">of any age</option>
            </select>
            <button type="submit" class="btn-ghost sm">Clear</button>
        </form>
    @endif

    {{-- The branch selector belongs on the result set (feature-rules §3.3),
         and only where there is a choice: in the branch workspace the scope
         bar has already said which site. --}}
    @include('recon::partials.run-grid', ['branches' => $pinned ? null : $branches])
</x-card>
