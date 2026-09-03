@props(['branches' => collect(), 'branchId' => null, 'workspace' => 'ho'])

{{--
    Scope is a parameter, not a report (plan §2). The bar carries the branch
    and the "as at" date on every screen, and the URL carries them too, so a
    link pasted into a message opens the same view for whoever receives it.
--}}
<div class="scopebar">
    <div class="shell-in scope-in">
        <form method="GET" class="scope-form">
            <label class="scope-field">
                <span>{{ $workspace === 'branch' ? 'Site' : 'Branch' }}</span>
                <select name="branch" onchange="this.form.submit()">
                    @if ($workspace !== 'branch')
                        <option value="">All sites</option>
                    @endif
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->BranchId }}" @selected((int) $branchId === (int) $branch->BranchId)>{{ $branch->Name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="scope-field">
                <span>As at</span>
                <input type="date" name="asat" value="{{ request('asat', now()->toDateString()) }}" onchange="this.form.submit()">
            </label>
            @if (request('ws'))<input type="hidden" name="ws" value="{{ request('ws') }}">@endif
        </form>
        <span class="scope-note">{{ $branches->count() }} sites in scope</span>
    </div>
</div>
