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

                <p class="field-help" style="margin-top:0">
                    <strong><span data-bulk-count>0</span> lines</strong> are ticked. Only the rows ticked on
                    the page you are looking at are included — the boxes cannot reach past it.
                </p>

                <div class="field-row">
                    <x-field name="flag" label="Flag"
                             :choices="$flags"
                             :value="old('flag')"
                             help="One flag per press. A form that sent seven at once could not tell 'off' from 'not sent', and would clear six nobody mentioned." />
                    <x-field name="value" label="Set it to"
                             :choices="['1' => 'Active', '0' => 'Inactive']"
                             :value="old('value', '1')" />
                </div>

                <x-field name="reason" label="Why" :value="old('reason')"
                         help="Kept against every line the batch touches. Required, because a master change nobody can explain later is what the override table exists to end." />

                <x-notice tone="info" title="What this writes">
                    <p>
                        It writes an <strong>Agora override</strong> per line and never touches
                        <code>PumpIT.dbo.STK_StockMaster</code>. Lines Agora does not already hold gain one,
                        seeded from whatever is in force right now — from that moment the <em>Held by</em>
                        column reads <code>agora</code> and a later change to the customer's own master no
                        longer reaches them.
                    </p>
                    <p>
                        A line whose override is <strong>parked</strong> stops the whole batch rather than
                        being quietly put back in force. Restore those one at a time first.
                    </p>
                </x-notice>

                <div class="btn-row">
                    <button type="submit" class="btn-primary">Set it on the selected lines</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-shell>
