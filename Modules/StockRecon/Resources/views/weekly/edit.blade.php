<x-app-shell :title="'Record action · '.$case->Description">
    <x-page-head eyebrow="Weekly stock investigation" :title="$case->Description">
        <x-slot:actions><a class="btn-ghost" href="{{ route('app.stockrecon.weekly.show', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">View evidence</a></x-slot:actions>
    </x-page-head>
    <x-card title="Investigation action" :sub="$case->Scenario.' · '.$case->ScenarioName">
        <p>{{ $case->NextAction }}</p>
        <form method="POST" action="{{ route('app.stockrecon.weekly.update', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">
            @csrf @method('PUT')
            <input type="hidden" name="ExpectedVersion" value="{{ old('ExpectedVersion', $case->VersionToken) }}">
            @error('refusal')<x-notice tone="stop">{{ $message }}</x-notice>@enderror
            @if ($case->Status !== 'Closed')
            <div class="field-row">
                <div class="field"><label for="weekly-status">Status</label><select name="Status" id="weekly-status" data-select required>
                    @foreach (['Open', 'Investigating', 'Waiting', 'Escalated'] as $status)<option @selected(old('Status', $case->Status) === $status)>{{ $status }}</option>@endforeach
                </select></div>
                <div class="field"><label for="weekly-owner">Owner</label><select name="OwnerId" id="weekly-owner" data-select>
                    <option value="">Unassigned</option>@foreach ($owners as $owner)<option value="{{ $owner->Id }}" @selected((string) old('OwnerId', $case->OwnerId) === (string) $owner->Id)>{{ $owner->UserName }}</option>@endforeach
                </select></div>
                <x-field name="DueDate" label="Due date" type="date" :value="old('DueDate', $case->DueDate)" />
            </div>
            <x-field name="Finding" label="Written finding" type="text" :value="old('Finding', $case->Finding)" help="Closing requires a finding of at least 20 characters supported by evidence." />
            <x-field name="EvidenceReference" label="Evidence reference" type="text" :value="old('EvidenceReference', $case->EvidenceReference)" help="Identify the dated count, delivery document, issue sheet or other evidence that supports your finding." />
            <div class="field-row">
                <div class="field"><label for="weekly-outcome">Closure outcome</label><select name="ClosureOutcome" id="weekly-outcome" data-select>
                    <option value="">Choose the evidenced outcome</option>
                    @foreach (['Real loss - staff charged', 'Real loss - key holder accountable', 'Paperwork corrected', 'Invoice / GRV corrected', 'Recount corrected', 'System / master data fixed', 'No action'] as $outcome)
                        <option @selected(old('ClosureOutcome', $case->ClosureOutcome) === $outcome)>{{ $outcome }}</option>
                    @endforeach
                </select></div>
                <x-field name="RecoveryAmount" label="Rand charged / recovered" type="number" step="0.01" min="0" :value="old('RecoveryAmount', $case->RecoveryAmount)" help="Enter zero if none. This records a substantiated amount separately from exposure and stock variance." />
            </div>
            <x-field name="RecoveryReference" label="Recovery evidence" :value="old('RecoveryReference', $case->RecoveryReference)" help="Identify the approved charge or recovery record and responsible person when recording an amount." />
            <div class="field"><label for="weekly-correction">Corrected in PumpIT</label><select name="CorrectionStatus" id="weekly-correction" data-select>
                <option value="">Choose</option>@foreach (['Y' => 'Yes — correction recorded', 'N' => 'No — explain why below'] as $value => $label)<option value="{{ $value }}" @selected(old('CorrectionStatus', $case->CorrectionStatus) === $value)>{{ $label }}</option>@endforeach
            </select></div>
            <x-field name="CorrectionReference" label="Correction reference or reason not required" :value="old('CorrectionReference', $case->CorrectionReference)" help="Record the corrected document, audit reference or why no correction is required. This form does not change PumpIT." />
            <x-field name="ActionTaken" label="Action completed" :value="old('ActionTaken', $case->ActionTaken)" help="State what was done and by whom, with the supporting record." />
            <x-field name="ActionDate" label="Action date" type="date" :value="old('ActionDate', $case->ActionDate)" />
            @can('stockrecon.weekly.approve')
                <x-field name="SignoffNote" label="Head-office sign-off" :value="old('SignoffNote')" help="Approval records your identity, approval time and closed date." />
            @endcan
            @else
                <x-notice tone="info">This case is signed and closed. Reopening preserves the previous approval in history and requires a fresh approval before closure.</x-notice>
            @endif
            <x-field name="Reason" label="Reason for this change" type="text" :value="old('Reason')" required />
            <div class="form-actions">
                @if ($case->Status === 'Closed')
                    @can('stockrecon.weekly.reopen')<button class="btn-primary" type="submit" formaction="{{ route('app.stockrecon.weekly.reopen', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">Reopen investigation</button>@endcan
                @else
                    @can('stockrecon.weekly.save')<button class="btn-primary" type="submit">Save investigation action</button>@endcan
                    @if (app(\App\Support\BranchContext::class)->workspace() === 'ho')
                        @can('stockrecon.weekly.approve')<button class="btn-primary" type="submit" formaction="{{ route('app.stockrecon.weekly.approve', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">Approve and close</button>@endcan
                    @endif
                @endif
            </div>
        </form>
    </x-card>
</x-app-shell>
