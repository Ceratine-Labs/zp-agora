@props(['branches' => collect(), 'branchId' => null, 'workspace' => 'ho'])

{{--
    Scope is a parameter, not a report (plan §2), and the URL carries it so a
    link pasted into a message opens the same view for whoever receives it.

    The branch control belongs to ONE workspace, not both:

      Head office  estate-wide by definition. No selector. A head-office screen
                   that needs a single site asks for one itself, on the result
                   set, which is where feature-rules §3.3 puts the branches
                   component. A selector here as well is not two ways to say
                   the same thing — it is two answers that disagree, and on the
                   first cut of the recon workbench the form silently won while
                   this bar showed a different site.

      Branch       the workspace IS "I am working at one site", so saying which
                   one is the whole job of this bar. A user granted several
                   sites switches between them here; one granted a single site
                   sees a select with one option in it, which is honest.
--}}
<div class="scopebar">
    <div class="shell-in scope-in">
        <form method="GET" class="scope-form">
            @if ($workspace === 'branch')
                <label class="scope-field">
                    <span>Site</span>
                    <select name="branch" onchange="this.form.submit()">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->BranchId }}" @selected((int) $branchId === (int) $branch->BranchId)>{{ $branch->Name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="scope-field">
                <span>As at</span>
                <input type="date" name="asat" value="{{ request('asat', now()->toDateString()) }}" onchange="this.form.submit()">
            </label>
            @if (request('ws'))<input type="hidden" name="ws" value="{{ request('ws') }}">@endif
        </form>
        <span class="scope-note">
            {{ $workspace === 'branch'
                ? $branches->count().' '.\Illuminate\Support\Str::plural('site', $branches->count()).' granted'
                : $branches->count().' sites in scope' }}
        </span>
    </div>
</div>
