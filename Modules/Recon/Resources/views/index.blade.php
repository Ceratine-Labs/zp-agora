<x-app-shell title="Auto reconciliation">
    <x-page-head
        eyebrow="Banking and reconciliation"
        title="Auto reconciliation"
        blurb="Preview what a reconciliation would do, then execute it. The preview reads the customer's
               bank statement and deposit tables and changes nothing." />

    @if ($stampMode === 'journal')
        <x-notice tone="info" collapsible title="Preview only, deliberately" style="margin-bottom:16px">
            <p>Execute writes the reconciliation stamp into PumpIT, and Agora does not write to the
               customer's databases without an explicit decision on the record. Until that decision exists,
               a run is previewed, recorded and reviewable, and nothing in PumpIT moves.</p>
            <p>There is a second reason. The procedure Execute would be replacing — the live
               <code>sp_AUTOReconcile_*_BankRecon</code> family — carries a comparison defect that stamps
               bank lines against deposits that do not exist, and we recommended in writing on
               18 August 2026 that ZP stop using it. Reproducing that behaviour behind a nicer button
               would be the wrong thing to ship.</p>
        </x-notice>
    @endif

    <x-card title="The five areas" sub="Each one previews through its own procedure on the Agora database">
        <div class="area-grid">
            @foreach ($areas as $key => $area)
                <a class="area-card" href="{{ route('app.recon.area', $key) }}">
                    <h3>{{ $area['label'] }}</h3>
                    <p>{{ $area['blurb'] }}</p>
                    <span class="area-proc">agora.{{ $area['procedure'] }}</span>
                </a>
            @endforeach
        </div>
    </x-card>

    <x-card title="Recent previews" sub="Every run is kept until somebody clears it" flush>
        @if ($recent->isNotEmpty() && $context->id() !== null)
            <x-slot:actions>
                <form method="POST" action="{{ route('app.recon.clear') }}"
                      data-confirm="Discard every preview for this site?"
                      data-confirm-text="Across all five areas. Nothing in PumpIT is affected — a preview is a record of a read. Any run that has been executed is kept."
                      data-confirm-action="Discard previews" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost">Clear previews</button>
                </form>
            </x-slot:actions>
        @endif

        <x-table :count="$recent->count()"
                 empty="No preview has been run yet.">
            <x-slot:head>
                <tr>
                    <th>Run</th>
                    <th>Area</th>
                    <th>Branch</th>
                    <th>Period</th>
                    <th class="num">Rows</th>
                    <th class="num">Would reconcile</th>
                    <th class="num">Value</th>
                    <th>Run at</th>
                </tr>
            </x-slot:head>

            @foreach ($recent as $run)
                <tr>
                    <td><a href="{{ route('app.recon.run', $run) }}">#{{ $run->Id }}</a></td>
                    <td>{{ config("recon.areas.{$run->ReconArea}.label", $run->ReconArea) }}</td>
                    <td class="num">{{ $run->BranchId }}</td>
                    <td class="mono">{{ $run->FromDate?->toDateString() }} → {{ $run->ToDate?->toDateString() }}</td>
                    <td class="num">{{ \App\Support\Format::n($run->TotalRows) }}</td>
                    <td class="num">{{ \App\Support\Format::n($run->MatchedRows) }}</td>
                    <td class="num">{{ \App\Support\Format::r($run->MatchedTotal) }}</td>
                    <td class="muted">{{ $run->CreatedAt?->diffForHumans() }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-app-shell>
