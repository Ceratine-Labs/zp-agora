{{--
    Tab three: the runs made in this area.

    Yours unless you ask for everyone's — and the switch is <x-tabs> in link
    mode, not two hand-written anchors. It carries `?scope=` like every other
    scope in Agora, so a particular view of the list is something you can send
    to somebody, and ReconRunGrid reads the same query string, so the grid and
    the switch cannot disagree about which way it is set.
--}}
@php($mine = \Modules\Recon\Grids\ReconRunGrid::wantsOwnRunsOnly())

<x-card title="Runs"
        :sub="$mine ? 'The previews you have made in this area' : 'Every preview made in this area, whoever made it'"
        flush>
    <x-slot:actions>
        <x-tabs label="Whose runs"
                :active="$mine ? 'mine' : 'all'"
                :items="[
                    ['key' => 'mine', 'label' => 'Mine', 'href' => route('app.recon.runs', $area['key'])],
                    ['key' => 'all', 'label' => 'Everyone', 'href' => route('app.recon.runs', [$area['key'], 'scope' => 'all'])],
                ]" />
    </x-slot:actions>

    {{-- The branch selector belongs on the result set (feature-rules §3.3),
         and only where there is a choice: in the branch workspace the scope
         bar has already said which site. --}}
    <x-data-grid :grid="$grid" :branches="$pinned ? null : $branches" />
</x-card>
