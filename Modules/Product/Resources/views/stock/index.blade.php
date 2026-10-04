{{--
    The stock recon master listing (T025).

    A grid like every other user-facing result set, so it inherits header
    filters, the CSV extract, the column chooser, the branch selector and the
    visible procedure name without this page knowing about any of them.

    THE PAGE IS THE GRID. It carried an eyebrow, a three-line blurb and a
    collapsible "where each column comes from" panel above the filters, and on
    21 September Ryan measured what that cost: half the screen before the first
    row. The provenance those blocks explained belongs on the columns, not in a
    preamble everybody scrolls past — the Pricing column already says why a GP
    is blank, and Last counted reads as a date or as blank.

    THE BATCH ACTION (Ryan, 22 September 2026) hangs off the grid's own
    selection bar, which has existed since T014 and until now had nothing to
    offer: `bulk` is its slot, and it is hidden until a row is ticked. One
    button, because the choice of WHICH flag belongs in the form beside the
    reason rather than as seven buttons in a pill.
--}}
<x-app-shell title="Stock recon master">
    <x-page-head title="Stock recon master" />

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That batch was refused">{{ $message }}</x-notice>
    @enderror

    {{-- A failed FormRequest lands back here with the modal shut, so the field
         errors inside it would never be read. Said once, out here, where it
         is. --}}
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That batch was not sent">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    <x-data-grid :grid="$grid" surface>
        @can('master.stock.edit')
            <x-slot:bulk>
                <button type="button" class="btn-ghost" data-modal-open="stock-flags">Set a flag…</button>
            </x-slot:bulk>
        @endcan
    </x-data-grid>

    @can('master.stock.edit')
        <x-modal id="stock-flags" title="Set a flag on the selected lines">
            {{--
                `data-bulk-form` names the grid whose ticks this form carries.
                bulk-selection.js keeps the hidden inputs and the count in step
                with the boxes — see its header for why the form cannot simply
                contain them.
            --}}
            <form method="POST" action="{{ route('app.master.stock.flags') }}"
                  data-bulk-form="app.master.stock">
                @csrf
                @method('PUT')

                <div data-bulk-items></div>

                {{-- What a press writes sits behind the "i" on this line (4 Oct
                     2026), at the top so its panel opens over the form rather
                     than past the dialog's bottom edge. It never touches
                     PumpIT, so there is no live-write warning to keep. --}}
                <div class="field-help" style="margin:0 0 1em">
                    <strong><span data-bulk-count>0</span> lines</strong> ticked, on this page only.
                    <x-info label="What this writes">
                        <p>An <strong>Agora override</strong> per line; <code>PumpIT.dbo.STK_StockMaster</code> is never
                           touched. A line Agora does not hold yet is seeded from what is in force now, and from then on
                           <em>Held by</em> reads <code>agora</code> and the customer's own master no longer reaches it.</p>
                        <p>A line whose override is <strong>parked</strong> stops the whole batch. Restore those one at a
                           time first.</p>
                    </x-info>
                </div>

                <div class="field-row">
                    <x-field name="flag" label="Flag"
                             :choices="$flags"
                             :value="old('flag')"
                             info="One flag per press, so a batch never clears a flag nobody mentioned." />
                    <x-field name="value" label="Set it to"
                             :choices="['1' => 'Active', '0' => 'Inactive']"
                             :value="old('value', '1')" />
                </div>

                <x-field name="reason" label="Why" :value="old('reason')"
                         info="Required. Kept against every line the batch touches." />

                <div class="btn-row">
                    <button type="submit" class="btn-primary">Set it on the selected lines</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-shell>
