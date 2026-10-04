{{--
    Drop safe (T039) — PumpIT's drop safe capture and collection, for one site.

    A bag is cash the electronic safe could not take — coins, a safe out of
    order, a note it rejected — sealed in a numbered bag and dropped into the
    manual safe with a manager watching. It stays IN THE SAFE until the security
    company collects it; then its CollectionId names the collection. That is
    PumpIT's own rule, and the "In the safe" chip is exactly it.

    Scope (site; the days whose collections are listed) → what is in the safe,
    with the chip → drop a bag, or correct one still in the safe and on no
    cashup → tick bags and collect them → the collections of those days.

    WHAT REACHES PUMPIT, and nothing else, while cash.dropsafe.write_mode is
    'live': Drop (a BRN_DropSafe row), Correct (that row), Void (DELETES that
    row — only a bag in the safe and on no cashup, the whole row kept in Agora
    first), Collect (a BRN_DropSafe_Collection row and each bag's CollectionId).

    The standing explanation — what "in the safe" means, the rules a bag must
    meet, and what each press writes to PumpIT — sits behind the info button
    beside the title (ZRP, 4 Oct 2026). Refusals and the safe's own figures
    stay on the page; every press that writes says so in its confirmation.

    Props: $scope, $branches, $pinned, $refusal, $bags, $collections, $reasons,
           $limitReasons, $employees, $summary, $editing, $voiding, $writeMode,
           $threshold
--}}
@php
    $user = auth()->user();
    $live = $writeMode === 'live';
    $canDrop = $live && (bool) $user?->can('cash.dropsafe.save');
    $canEdit = $live && (bool) $user?->can('cash.dropsafe.edit');
    $canCollect = $live && (bool) $user?->can('cash.dropsafe.collect');
    $canVoid = $live && (bool) $user?->can('cash.dropsafe.void');
    $site = $scope->isChosen() ? $branches->firstWhere('BranchId', $scope->branchId)?->Name : null;
    $inSafe = (int) ($summary->InSafeBags ?? 0);
    $people = $employees->mapWithKeys(fn ($e) => [$e->EmployeeCode => trim(($e->EmployeeName ?: $e->EmployeeCode).' · '.$e->EmployeeCode)])->all();
    $now = $summary?->ServerNow ? \Illuminate\Support\Carbon::parse($summary->ServerNow) : now();
    $when = fn ($date, $time = null) => $date ? \Illuminate\Support\Carbon::parse($date)->format('j M Y').($time ? ' '.$time : '') : \App\Support\Format::NOTHING;
@endphp

<x-app-shell title="Drop safe">
    <x-page-head eyebrow="Branch functions · Cash" title="Drop safe">
        <x-slot:info>
            <p>A bag is <strong>in the safe</strong> from the drop until the security company collects it — PumpIT's
               own rule; being on a cashup does not take it out. Every bag needs the manager who witnessed it, and one
               over {{ \App\Support\Format::r($threshold) }} a reason. A bag number is used once at a site, and so is
               the security company's bag.</p>
            <p>A bag can be corrected, or voided if it was captured in error, only while it is in the safe and on no
               cashup. Agora keeps what each correction replaced, and the whole of a voided row with its reason.</p>
            @if ($live)
                <p><strong>Dropping, correcting and collecting write to PumpIT</strong> (<code>BRN_DropSafe</code>,
                   <code>BRN_DropSafe_Collection</code>), as its own screens write them; a void deletes the bag's row
                   there. What each press wrote is kept in Agora.</p>
            @else
                <p><strong>Writing to PumpIT is off.</strong> This screen shows the safe and its collections; nothing
                   is written to PumpIT.</p>
            @endif
        </x-slot:info>
        <x-slot:actions>
            @if ($scope->isChosen())
                <x-chip :tone="\Modules\Cash\Services\DropSafeService::inSafeTone($inSafe)">In the safe: {{ \App\Support\Format::n($inSafe) }}{{ $inSafe > 0 ? ' · '.\App\Support\Format::r($summary->InSafeAmount ?? 0) : '' }}</x-chip>
            @endif
            <a class="btn-ghost" href="{{ route('app.cash.dropsafe.bags') }}">Drop safe bags</a>
            <a class="btn-ghost" href="{{ route('app.cash.dropsafe.phone', $scope->withBag(null)->query()) }}">On a phone</a>
            @can('cash.cashup.view')
                <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->isChosen() ? ['branch_id' => $scope->branchId] : []) }}">Daily Banking</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @include('cash::dropsafe.partials.outcome')

    <x-card title="Which site">
        <x-slot:info>
            <p>Everything in the site's safe is listed, however old. The days choose which collections are shown
               beside it.</p>
        </x-slot:info>
        <form method="GET" action="{{ route('app.cash.dropsafe') }}">
            <x-params legend="Scope">
                @if ($pinned)
                    <x-param label="Site">
                        <p class="field-help">{{ $site ?? 'Your workspace site' }} — your workspace is pinned to it.</p>
                    </x-param>
                @else
                    <x-param name="branch_id" label="Site" searchable
                             :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                             :value="$scope->branchId" />
                @endif
                <x-param name="date_from" label="Collections from" type="date" :value="$scope->from" />
                <x-param name="date_to" label="To" type="date" :value="$scope->to" />
            </x-params>
            <div class="form-actions">
                <button type="submit" class="btn">Show the safe</button>
            </div>
        </form>
    </x-card>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Pick the site, and what is in its safe appears here — with the form to drop a bag and to collect them." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The safe could not be read">
            <p>{{ $refusal }}</p>
        </x-notice>
    @else
        <x-card :title="($site ?? 'The site').' · the safe'" flush>
            <x-statstrip :stats="[
                ['label' => 'In the safe', 'value' => \App\Support\Format::n($inSafe), 'note' => \App\Support\Format::r($summary->InSafeAmount ?? 0),
                 'tone' => \Modules\Cash\Services\DropSafeService::inSafeTone($inSafe)],
                ['label' => 'Oldest', 'value' => $summary?->OldestDropDate ? \Illuminate\Support\Carbon::parse($summary->OldestDropDate)->format('j M Y') : \App\Support\Format::NOTHING,
                 'note' => $summary?->OldestDropDate ? \Illuminate\Support\Carbon::parse($summary->OldestDropDate)->diffForHumans($now, ['parts' => 1]) : 'nothing waiting',
                 'tone' => 'neutral'],
                ['label' => 'On a cashup', 'value' => \App\Support\Format::n($summary->InSafeOnCashup ?? 0), 'note' => 'of '.\App\Support\Format::n($inSafe).' in the safe', 'tone' => 'neutral'],
                ['label' => 'Collected', 'value' => \App\Support\Format::n($summary->Collections ?? 0),
                 'note' => \App\Support\Format::r($summary->CollectedAmount ?? 0).' in these days', 'tone' => 'neutral'],
            ]" />
        </x-card>

        @if ($voiding && $canVoid)
            <x-card :title="'Void bag '.$voiding->BagNo" style="margin-top:16px">
                <x-slot:info>
                    <p>For a bag captured in error — one that was never dropped. It is still in the safe and on no
                       cashup, so it can go: its row is deleted from PumpIT and kept whole in Agora with your reason,
                       which is the only record of why it left.</p>
                </x-slot:info>
                <form method="POST" action="{{ route('app.cash.dropsafe.void') }}" id="dropsafe-void"
                      data-confirm="Void bag {{ $voiding->BagNo }} in PumpIT?"
                      data-confirm-text="The bag's row is DELETED from PumpIT's drop safe: {{ \App\Support\Format::r($voiding->Amount) }}, dropped {{ substr((string) $voiding->DropDate, 0, 10) }} by {{ $voiding->CashierName ?? $voiding->CashierCode }}. The whole row is kept in Agora with your reason, and the bag number can be used again. This cannot be undone in PumpIT."
                      data-confirm-action="Void the bag" data-confirm-danger data-loader="Writing to PumpIT…">
                    @csrf
                    @foreach ($scope->withBag(null)->query() as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="hidden" name="bag_no" value="{{ $voiding->BagNo }}">
                    <x-params legend="Why">
                        <x-param name="void_reason" label="Reason" :value="old('void_reason')"
                                 placeholder="Why this bag was never a bag — kept in Agora"
                                 help="Required." />
                    </x-params>
                    <div class="form-actions">
                        <button type="submit" class="btn">Void the bag</button>
                    </div>
                </form>
            </x-card>
        @endif

        @if (($editing && $canEdit) || (! $editing && $canDrop))
            <x-card :title="$editing ? 'Correct bag '.$editing->BagNo : 'Drop a bag'" style="margin-top:16px">
                <x-slot:info>
                    @if ($editing)
                        <p>Still in the safe and on no cashup, so it can be corrected, its number included. Every change
                           is kept in Agora beside what it replaced.</p>
                    @else
                        <p>Every bag needs the manager who witnessed it; one over {{ \App\Support\Format::r($threshold) }}
                           needs a reason too. The bag stays in the safe until it is collected.</p>
                    @endif
                </x-slot:info>
                @include('cash::dropsafe.partials.drop-form', ['from' => 'desk'])
            </x-card>
        @endif

        <x-card title="In the safe" flush style="margin-top:16px">
            <x-slot:info>
                <p>Every bag at this site not yet collected, oldest first — the bags PumpIT's own collection screen
                   offers.</p>
            </x-slot:info>
            @if ($canCollect && $bags->isNotEmpty())
            <form method="POST" action="{{ route('app.cash.dropsafe.collect') }}" id="dropsafe-collect"
                  data-confirm="Collect the ticked bags in PumpIT?"
                  data-confirm-text="One collection is written to PumpIT under the security company's bag number, with the ticked bags counted and added up, and each ticked bag leaves the safe. Nothing is deleted."
                  data-confirm-action="Collect in PumpIT" data-loader="Writing to PumpIT…">
                @csrf
                @foreach ($scope->withBag(null)->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
            @endif

                <x-table :count="$bags->count()" procedure="agora.usp_Cash_GetDropSafe" dense tools
                         empty="The safe is empty — every bag at this site has been collected.">
                    <x-slot:head>
                        <tr>
                            @if ($canCollect)
                                <th class="pick" title="Tick the bags the security company is taking">Take</th>
                            @endif
                            <th>Bag</th>
                            <th>Dropped</th>
                            <th class="num">Amount</th>
                            <th>Why the manual safe</th>
                            <th>Reason over {{ \App\Support\Format::r($threshold) }}</th>
                            <th>Cashier</th>
                            <th>Manager</th>
                            <th>Cashup</th>
                            <th>Collected</th>
                            <th>Captured</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($bags as $b)
                        <tr>
                            @if ($canCollect)
                                <td class="pick">
                                    <input type="checkbox" name="bags[]" value="{{ $b->BagNo }}"
                                           @checked(in_array($b->BagNo, (array) old('bags', []), true))
                                           aria-label="Collect bag {{ $b->BagNo }}">
                                </td>
                            @endif
                            <td class="mono">
                                @if (($canEdit || $canVoid) && $b->CanEdit)
                                    <a href="{{ route('app.cash.dropsafe', $scope->withBag($b->BagNo)->query()) }}" title="Correct or void this bag">{{ $b->BagNo }}</a>
                                @else
                                    {{ $b->BagNo }}
                                @endif
                            </td>
                            <td>{{ $when($b->DropDate, $b->DropTime) }}<br><span class="muted">{{ \App\Support\Format::n($b->AgeDays) }} days ago</span></td>
                            <td class="num">
                                {{ \App\Support\Format::r($b->Amount) }}
                                @if ($b->NeedsReason)<br><x-chip tone="serious">no reason given</x-chip>@endif
                            </td>
                            <td>{{ $b->ReasonForManualBag ?? \App\Support\Format::NOTHING }}@if ($b->RefNoFaultReported)<br><span class="muted mono">{{ $b->RefNoFaultReported }}</span>@endif</td>
                            <td>{{ $b->LimitReason ?? \App\Support\Format::NOTHING }}</td>
                            <td>{{ $b->CashierName ?? $b->CashierCode }}</td>
                            <td>{{ $b->ManagerName ?? $b->ManagerCode }}</td>
                            <td>
                                @if ($b->OnCashup)
                                    <x-chip tone="neutral">On a cashup</x-chip>@if ($b->OnLeg)<br><span class="muted">{{ $b->OnLeg }}</span>@endif
                                @else
                                    {{ \App\Support\Format::NOTHING }}
                                @endif
                            </td>
                            <td><x-chip tone="crit">In the safe</x-chip></td>
                            <td><span class="muted">{{ $b->CapturedIn === 'agora' ? 'in Agora' : 'in PumpIT' }}{{ $b->CapturedAt ? ', '.\Illuminate\Support\Carbon::parse($b->CapturedAt)->format('j M H:i') : '' }}</span></td>
                        </tr>
                    @endforeach
                </x-table>

            @if ($canCollect && $bags->isNotEmpty())
                <x-params legend="The collection" style="margin:12px">
                    <x-param name="dbag_no" label="Security company's bag" :value="old('dbag_no')" placeholder="The number the bank will quote" />
                    <x-param name="security_name" label="Guard" :value="old('security_name')" placeholder="Who took the bags" />
                    <x-param name="handed_by" label="Manager handing over" searchable :choices="['' => 'Choose the manager'] + $people" :value="old('handed_by')" />
                    <x-param name="collection_date" label="Collected on" type="date" :value="old('collection_date', $now->toDateString())" :max="$now->toDateString()" />
                    <x-param name="collection_time" label="At" type="time" step="1" :value="old('collection_time', $now->format('H:i:s'))" />
                    <x-param name="collect_note" label="Note" :value="old('collect_note')" placeholder="Optional — kept in Agora" />
                </x-params>
                <div class="form-actions" style="margin:0 12px 12px">
                    <button type="submit" class="btn primary">Collect the ticked bags</button>
                </div>
            </form>
            @endif
        </x-card>

        <x-card title="Collections" :sub="\Illuminate\Support\Carbon::parse($scope->from)->format('j M').' – '.\Illuminate\Support\Carbon::parse($scope->to)->format('j M Y')" flush style="margin-top:16px">
            <x-slot:info>
                <p>Collected from this safe in these days, newest first. Where a collection declares a different count
                   or total from what its bags add up to, the declared figure is shown beside it.</p>
            </x-slot:info>
            <x-table :count="$collections->count()" procedure="agora.usp_Cash_GetDropSafe" dense tools
                     empty="Nothing was collected from this safe in these days.">
                <x-slot:head>
                    <tr>
                        <th>Collected</th>
                        <th>Security company's bag</th>
                        <th class="num">Bags</th>
                        <th class="num">Amount</th>
                        <th>Manager</th>
                        <th>Guard</th>
                        <th>Captured</th>
                    </tr>
                </x-slot:head>
                @foreach ($collections as $c)
                    @php($agrees = (int) $c->Bags === (int) $c->DeclaredBags && abs((float) $c->Amount - (float) $c->DeclaredAmount) < 0.005)
                    <tr>
                        <td>{{ $when($c->CollectionDate, $c->CollectionTime) }}</td>
                        <td class="mono">{{ $c->DBagNo }}<br><span class="muted">collection {{ $c->CollectionId }}</span></td>
                        <td class="num">{{ \App\Support\Format::n($c->Bags) }}@unless ($agrees)<br><span class="muted">declared {{ \App\Support\Format::n($c->DeclaredBags) }}</span>@endunless</td>
                        <td class="num">
                            {{ \App\Support\Format::r($c->Amount) }}
                            @unless ($agrees)<br><x-chip tone="serious">declared {{ \App\Support\Format::r($c->DeclaredAmount) }}</x-chip>@endunless
                        </td>
                        <td>{{ $c->ManagerName ?? $c->ManagerCode }}</td>
                        <td>{{ $c->SecurityName }}</td>
                        <td><span class="muted">{{ $c->CapturedIn === 'agora' ? 'in Agora' : 'in PumpIT' }}{{ $c->CapturedAt ? ', '.\Illuminate\Support\Carbon::parse($c->CapturedAt)->format('j M H:i') : '' }}</span></td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>

    @endif
</x-app-shell>
