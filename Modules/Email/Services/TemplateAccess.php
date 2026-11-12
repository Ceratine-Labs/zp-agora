<?php

namespace Modules\Email\Services;

use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;

final class TemplateAccess
{
    public function __construct(private BranchContext $context) {}

    public function mayEditGlobal(): bool
    {
        return ! $this->context->isBranchWorkspace() && $this->context->allowed() === [];
    }

    /** @return Collection<int, string> */
    public function scopes(): Collection
    {
        $branches = Branch::query()->acrossBranches()->visibleTo($this->context->allowed())
            ->where('BranchId', '<>', $this->context->groupId());
        if ($this->context->isBranchWorkspace()) {
            $branches->where('BranchId', $this->context->id());
        }
        $choices = $branches->ordered()->pluck('Name', 'BranchId');
        if ($this->mayEditGlobal()) {
            $choices->prepend('Group default (all branches)', $this->context->groupId());
        }

        return $choices;
    }

    public function check(int $branchId, bool $write = false): void
    {
        if ($branchId === $this->context->groupId()) {
            abort_if($write && ! $this->mayEditGlobal(), 403, 'Only unrestricted head office may edit a group default.');

            return; // Every permitted template viewer may read their fallback.
        }
        abort_unless($this->scopes()->has($branchId), 403, 'This template scope is not available to you.');
    }
}
