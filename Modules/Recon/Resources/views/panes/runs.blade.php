{{--
    Tab three: the runs made in this area.

    Two switches, both <x-tabs> in link mode rather than hand-written anchors,
    and both carried in the query string so a particular view of the list is
    something you can send to somebody. ReconRunGrid reads the same two
    parameters, so the grid and the switches cannot disagree about which way
    they are set.

      Mine / Everyone     `?scope=`  — yours unless you ask.
      Open work / All     `?show=`   — open work unless you ask (23 Sep 2026).
                          On live that morning the clerks' own lists held
                          1,136 open previews and 236 failed runs; history is
                          one switch away rather than in the way.

    Each switch keeps the other's setting, so flipping one never quietly resets
    the other.
--}}
@php($mine = \Modules\Recon\Grids\ReconRunGrid::wantsOwnRunsOnly())
@php($openOnly = \Modules\Recon\Grids\ReconRunGrid::wantsOpenOnly())
@php($runsUrl = fn (bool $m, bool $o) => route('app.recon.runs', array_filter([
    'area' => $area['key'],
    'scope' => $m ? null : 'all',
    'show' => $o ? null : 'all',
])))
@php($site = app(\App\Support\BranchContext::class)->id())

<x-card title="Runs"
        :sub="($openOnly ? 'Open work — ' : 'Every run — ')
              .($mine ? 'the previews you have made in this area' : 'everyone\'s, in this area')"
        flush>
    <x-slot:actions>
        <x-tabs label="Which runs"
                :active="$openOnly ? 'open' : 'all'"
                :items="[
                    ['key' => 'open', 'label' => 'Open work', 'href' => $runsUrl($mine, true)],
                    ['key' => 'all', 'label' => 'Everything', 'href' => $runsUrl($mine, false)],
                ]" />
        <x-tabs label="Whose runs"
                :active="$mine ? 'mine' : 'all'"
                :items="[
                    ['key' => 'mine', 'label' => 'Mine', 'href' => $runsUrl(true, $openOnly)],
                    ['key' => 'all', 'label' => 'Everyone', 'href' => $runsUrl(false, $openOnly)],
                ]" />
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
    <x-data-grid :grid="$grid" :branches="$pinned ? null : $branches" />
</x-card>
