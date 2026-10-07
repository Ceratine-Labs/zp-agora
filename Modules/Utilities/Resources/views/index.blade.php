{{--
    Utility meters (T056).

    One site, one day to capture, and that day's month around it. Built as ONE
    screen for the desk and the phone: Agora has no mobile view resolver yet
    (plan §3.9 — no DeviceContext, no pick(), no mobile layout), so the capture
    form is deliberately a day rather than a month grid. On a phone that is a
    short stack of fields, one per meter; on a desk it is one row of them. The
    month and the readings grid sit in containers that scroll on their own, so
    nothing makes the page scroll sideways at 375px.

    Order on the page is the order of the job: what to type now, what is wrong,
    what it came to, and then everything.
--}}
@php
    use App\Support\Format;
    use App\Grid\StatusTone;

    // A number for an <input type="number">: no grouping, no trailing zeros.
    // The house format (spaces) is for reading; a form field is for typing.
    $plain = fn ($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    $when = fn ($d) => $d === null ? null : \Illuminate\Support\Carbon::parse($d)->format('j M');

    $editable = fn ($line) => $writesOn && $maySave && (! $line->IsCaptured || $mayEdit);
    $anyCaptured = $lines->contains(fn ($l) => (bool) $l->IsCaptured);
    $open = $lines->filter($editable)->values();
    $shut = $lines->reject($editable)->values();

    $captured = (int) $meters->sum('DaysCaptured');
    $due = (int) $meters->sum('DaysDue');
    $cost = $meters->whereNotNull('Cost')->sum('Cost');
    $costed = $meters->whereNotNull('Cost')->count();
    $ongoing = $zero->where('IsOngoing', 1)->count();

    // Agora's timestamps are UTC (SYSUTCDATETIME in the procedures, and the
    // application's own clock). Parsed as UTC, so "how long ago" is right
    // whatever the server's local zone. PumpIT's own CreateDateTime is the
    // server's local clock and is never mixed with these.
    $savedAt = $lastSave?->CreatedAt ? \Illuminate\Support\Carbon::parse($lastSave->CreatedAt, 'UTC') : null;
@endphp
<x-app-shell :title="__('utilities::utilities.title')">
    {{-- The standing explanation on this screen — what it is for, how usage
         is worked out, what gets flagged — sits behind info buttons beside the
         titles (Ryan, 4 Oct 2026). Figures, the day, and anything about THIS
         save stay on the page. --}}
    <x-page-head eyebrow="Capture" :title="__('utilities::utilities.title')">
        <x-slot:info>
            <p>{{ __('utilities::utilities.blurb') }}</p>
        </x-slot:info>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    {{-- Flagged on capture: anything the save just left standing still. --}}
    @if (! empty(session('flagged')))
        <x-notice tone="warn" title="Standing still, or not read, after this save">
            <ul>
                @foreach (session('flagged') as $flag)
                    <li>{{ $flag }}</li>
                @endforeach
            </ul>
            <p>Listed under <em>Meters standing still</em> below.</p>
        </x-notice>
    @endif

    @if (session('refusal') || $refusal)
        <x-notice tone="stop" title="That was not recorded">{{ session('refusal') ?? $refusal }}</x-notice>
    @endif

    {{-- The scope. Chosen once, then folded to one line so the work leads. --}}
    <x-scope-line :open="$branchId === null"
                  :parts="[$branchName ?? 'Choose a site', $day->format('j M Y'), 'Month to date: '.$monthFrom->format('F Y')]">
        <form method="GET" action="{{ route('app.utilities.index') }}">
            <div class="field-row">
                @if ($pinned && $branchId !== null)
                    {{-- Stated, not offered: the branch workspace is pinned,
                         and a picker here would be a choice that does not
                         exist. Same markup as the stock recon centre's. --}}
                    <div class="field">
                        <label>Site</label>
                        <p class="field-fixed">{{ $branchName }}</p>
                    </div>
                @else
                    <x-field name="branch_id" label="Site"
                             :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                             :value="$branchId" />
                @endif
                <x-field name="date" label="Day to capture" type="date"
                         :value="$day->toDateString()" :max="\App\Support\BusinessDate::today()->toDateString()"
                         info="Today, unless you are catching up. The month shown is this day's month." />
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Show</button>
            </div>
        </form>
    </x-scope-line>

    @if ($branchId !== null && $refusal === null)
        <x-kpi-strip>
            <x-kpi label="Readings this month"
                   :value="Format::n($captured).' of '.Format::n($due)"
                   note="meter-days read, {{ $monthFrom->format('j M') }} to {{ $monthTo->format('j M') }}"
                   :tone="$due > 0 && $captured < $due ? 'warn' : 'good'" />
            <x-kpi label="Cost this month"
                   :value="$costed > 0 ? Format::rk($cost) : Format::n(null)"
                   :note="$costed > 0 ? 'at tariff, '.$costed.' of '.$meters->count().' meters costed' : 'no tariff is set for these meter types yet'" />
            <x-kpi label="Meters standing still"
                   :value="Format::n($ongoing)"
                   :tone="$ongoing > 0 ? 'warn' : 'good'" />
        </x-kpi-strip>

        {{-- ---- 1. Capture ------------------------------------------------ --}}
        <x-card :title="'Readings for '.$day->format('l j F')" sub="agora.usp_Utilities_PrepareReadings">
            <x-slot:info>
                <p>Saved by <code>agora.usp_Utilities_Save</code> into PumpIT's <code>BRN_UtilityTransaction</code>.</p>
                <p><strong>Prepaid units</strong> bought on the day are taken off the usage, so a purchase is not
                   counted as consumption.</p>
            </x-slot:info>
            @if ($lines->isEmpty())
                <x-empty-state title="No meters at this site"
                               text="PumpIT holds no utility meters for {{ $branchName }}. Meters are set up in PumpIT's BRN_UtilityMeter until the meter master screen exists." />
            @else
                @if (! $writesOn)
                    <x-notice tone="info" title="Recording is switched off">
                        Readings are shown but cannot be saved: utilities.write_mode is not <code>live</code>.
                    </x-notice>
                @elseif (! $maySave)
                    <x-notice tone="info" title="Read-only for you">
                        Recording a reading needs the <code>utilities.readings.save</code> permission.
                    </x-notice>
                @endif

                @if ($savedAt !== null)
                    <p class="field-help" title="{{ \App\Support\BusinessDate::show($savedAt) }} SAST">
                        Last recorded through Agora by {{ $lastSave->CreatedByName ?? 'an unknown user' }}
                        {{ $savedAt->diffForHumans() }}.
                    </p>
                @endif

                {{-- What is already recorded and not open to this person. --}}
                @if ($shut->isNotEmpty())
                    <x-facts>
                        @foreach ($shut as $line)
                            <x-fact :label="$line->MeterDescription ?? $line->MeterNo"
                                    :value="$line->ClosingReading === null ? null : Format::n($line->ClosingReading, 2)"
                                    :tone="StatusTone::of((string) $line->Status)">
                                {{ $line->Status }}@if ($line->Usage !== null) · usage {{ Format::n($line->Usage, 2) }}@endif
                                @if ($line->Cost !== null) · {{ Format::r($line->Cost) }}@endif
                                @if ($line->IsCaptured && $maySave && ! $mayEdit) · changing it needs <code>utilities.readings.edit</code>@endif
                            </x-fact>
                        @endforeach
                    </x-facts>
                @endif

                @if ($open->isNotEmpty())
                    <form method="POST" action="{{ route('app.utilities.save') }}" data-loader="Recording the readings…">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $branchId }}">
                        <input type="hidden" name="date" value="{{ $day->toDateString() }}">

                        <div class="field-row">
                            @foreach ($open as $i => $line)
                                <input type="hidden" name="lines[{{ $i }}][UtilityType]" value="{{ $line->UtilityType }}">
                                <input type="hidden" name="lines[{{ $i }}][MeterNo]" value="{{ $line->MeterNo }}">
                                <input type="hidden" name="lines[{{ $i }}][TransactionDate]" value="{{ $day->toDateString() }}">
                                <x-field :name="'lines['.$i.'][ClosingReading]'"
                                         :label="($line->MeterDescription ?? $line->MeterNo).' · closing'"
                                         type="number" step="any" min="0"
                                         :value="old('lines.'.$i.'.ClosingReading', $line->IsCaptured ? $plain($line->ClosingReading) : null)"
                                         :help="$line->OpeningReading === null
                                            ? 'No earlier reading for this meter.'
                                            : 'Last read '.Format::n($line->OpeningReading, 2).' on '.$when($line->PreviousReadingDate)
                                              .($line->Usage !== null ? ' · usage '.Format::n($line->Usage, 2) : '')
                                              .($line->Cost !== null ? ' · '.Format::r($line->Cost) : '')
                                              .($line->IsZeroRun ? ' · not moved for '.$line->ZeroRunDays.' days' : '')" />
                            @endforeach
                        </div>

                        {{-- Rare, so folded: a handful of meters in the estate
                             have ever had a prepaid purchase recorded. --}}
                        <details class="params-extra" @if ($open->contains(fn ($l) => (float) $l->PrepaidUnitsPurchased != 0)) open @endif>
                            <summary>Prepaid units bought on this day</summary>
                            <div class="field-row">
                                @foreach ($open as $i => $line)
                                    <x-field :name="'lines['.$i.'][PrepaidUnitsPurchased]'"
                                             :label="($line->MeterDescription ?? $line->MeterNo).' · prepaid units'"
                                             type="number" step="any"
                                             :value="old('lines.'.$i.'.PrepaidUnitsPurchased', (float) $line->PrepaidUnitsPurchased != 0 ? $plain($line->PrepaidUnitsPurchased) : null)" />
                                @endforeach
                            </div>
                        </details>

                        @if ($anyCaptured && $mayEdit)
                            <x-field name="reason" label="Why, if you are changing a reading already recorded"
                                     :value="old('reason')"
                                     info="Kept with the change, beside the value it replaced." />
                        @endif

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Record readings</button>
                            <span class="field-help">Leave a box empty for a meter you did not read. Writes to PumpIT.</span>
                        </div>
                    </form>
                @endif
            @endif
        </x-card>

        {{-- ---- 2. What is wrong -------------------------------------------- --}}
        <x-card title="Meters standing still"
                :sub="'agora.usp_Utilities_ZeroUsage · runs reaching into '.$monthFrom->format('F')">
            <x-slot:info>
                <p>A meter that has not moved for a while is faulty, not really being read, or switched off.
                   {{ $zeroRunRule }}</p>
                <p>The count at the top is the runs still going at the latest reading; this list also shows the
                   ones that have ended since.</p>
            </x-slot:info>
            <x-exception-list empty="Every meter at this site that is flagged at all moved, and was read, this month.">
                @foreach ($zero as $run)
                    <x-exception-row severity="warning"
                                     :title="$run->Title"
                                     :detail="$run->Detail.($run->IsOngoing
                                        ? ($run->Kind === 'No reading' ? ' Still not read.' : ' Still not moving at the latest reading.')
                                        : ($run->Kind === 'No reading' ? ' It has been read since.' : ' It has moved since.'))"
                                     :category="$run->Kind"
                                     :site="$run->BranchName"
                                     :age="$run->RunDays.' days'" />
                @endforeach
            </x-exception-list>
        </x-card>

        {{-- ---- 3. The month, per meter -------------------------------------
             A day PumpIT holds as 0 / 0 — the legacy screen writes the rest of
             the month ahead like that — is not a reading (Ryan, PM decision
             01a0e312-07a0). It has no usage and is never an opening, so the
             month starts from the last real reading before it and ends at the
             last real reading in it; unread days show only in "Days read". --}}
        <x-card :title="$monthFrom->format('F Y').' by meter'" flush>
            <x-slot:info>
                <p>Usage is closing − opening − prepaid, the customer's own formula: the last reading, less the
                   reading before the month, less prepaid units.</p>
                <p>A day PumpIT holds as 0 / 0 has not been read: it is not counted, and the next reading is measured
                   from the last real one.</p>
                @if ($untariffed !== [])
                    <p>PumpIT stores no tariff; for now tariffs are set in <code>utilities.tariffs</code> in Agora's
                       configuration.</p>
                @endif
            </x-slot:info>
            <x-table procedure="agora.usp_Utilities_PrepareReadings" :count="$meters->count()"
                     empty="No meters at this site." fit>
                <x-slot:head>
                    <tr>
                        <th>Meter</th>
                        <th>Type</th>
                        <th class="num">Opening</th>
                        <th class="num">Closing</th>
                        <th class="num">Prepaid</th>
                        <th class="num">Usage</th>
                        <th class="num">Tariff</th>
                        <th class="num">Cost</th>
                        <th class="num">Days read</th>
                    </tr>
                </x-slot:head>
                @foreach ($meters as $meter)
                    <tr>
                        <td>{{ $meter->MeterDescription ?? $meter->MeterNo }}</td>
                        <td>{{ $meter->UtilityType }} · {{ $meter->UtilityTypeDescription }}</td>
                        <td class="num" title="{{ $meter->OpeningDate ? 'Read on '.$when($meter->OpeningDate) : 'No earlier reading' }}">{{ Format::n($meter->OpeningReading, 2) }}</td>
                        <td class="num" title="{{ $meter->ClosingDate ? 'Read on '.$when($meter->ClosingDate) : '' }}">{{ Format::n($meter->ClosingReading, 2) }}</td>
                        <td class="num">{{ Format::n($meter->PrepaidUnitsPurchased, 2) }}</td>
                        <td class="num">{{ Format::n($meter->Usage, 2) }}</td>
                        <td class="num">{{ $meter->Tariff === null ? Format::n(null) : Format::r($meter->Tariff, 4) }}</td>
                        <td class="num">{{ Format::r($meter->Cost) }}</td>
                        <td class="num" title="{{ (int) $meter->BlankDays }} held in PumpIT as 0 / 0, not read">{{ Format::n($meter->DaysCaptured) }} / {{ Format::n($meter->DaysDue) }}</td>
                    </tr>
                @endforeach
            </x-table>
            @if ($untariffed !== [])
                <x-slot:foot>
                    <div class="card-pad">
                        <p class="field-help">
                            No tariff is set for meter type{{ count($untariffed) === 1 ? '' : 's' }}
                            <code>{{ implode(', ', $untariffed) }}</code>, so cost shows as a dash rather than R0.00.
                        </p>
                    </div>
                </x-slot:foot>
            @endif
        </x-card>
    @elseif ($branchId === null)
        <x-card title="Choose a site">
            <x-empty-state text="Readings are recorded one site at a time. Choose the site above; the readings of every site you are granted are in the grid below." />
        </x-card>
    @endif

    {{-- ---- 4. Every reading ----------------------------------------------
         Every site this person may see, not just the one above: head office
         reads it for the meter nobody is reading. Its own scope, because it
         answers a different question from the capture card. --}}
    <x-card :title="$grid->definition->title()" flush>
        <x-slot:info>
            <p>{{ $grid->definition->blurb() }}</p>
        </x-slot:info>
        <x-data-grid :grid="$grid" :branches="$gridBranches" />
    </x-card>
</x-app-shell>
