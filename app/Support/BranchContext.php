<?php

namespace App\Support;

/**
 * Which branch the current request is looking at.
 *
 * Two workspaces (plan §2): Head Office sees every site the user is granted,
 * Branch sees exactly one. The global scope on BaseModel reads this, so a
 * controller never writes `where('BranchId', ...)` by hand — and cannot
 * forget to.
 *
 * `withoutScope()` is the one sanctioned way past it, for the estate-wide
 * reads (the group league table, the Exco pack) that are branch-scoped by
 * the query itself rather than by the row.
 */
class BranchContext
{
    protected ?int $branchId = null;

    protected string $workspace = 'ho';

    /** @var array<int, int>|null Branch ids the signed-in user may see; null = not yet resolved. */
    protected ?array $allowed = null;

    protected bool $scopeSuspended = false;

    public function set(?int $branchId): self
    {
        $this->branchId = $branchId;

        return $this;
    }

    public function id(): ?int
    {
        return $this->branchId;
    }

    public function workspace(): string
    {
        return $this->workspace;
    }

    public function setWorkspace(string $workspace): self
    {
        $this->workspace = in_array($workspace, ['ho', 'branch'], true) ? $workspace : 'ho';

        return $this;
    }

    public function isBranchWorkspace(): bool
    {
        return $this->workspace === 'branch';
    }

    /** @param array<int, int> $branchIds */
    public function setAllowed(array $branchIds): self
    {
        $this->allowed = array_values(array_unique(array_map('intval', $branchIds)));

        return $this;
    }

    /** @return array<int, int> */
    public function allowed(): array
    {
        return $this->allowed ?? [];
    }

    public function maySee(int $branchId): bool
    {
        return $this->allowed === null || in_array($branchId, $this->allowed, true);
    }

    /** The group entity's id — what non-branch rows carry instead of NULL. */
    public function groupId(): int
    {
        return (int) config('agora.group_branch_id');
    }

    public function scopeSuspended(): bool
    {
        return $this->scopeSuspended;
    }

    /**
     * Run a callback with branch scoping off — for genuinely estate-wide
     * reads. Restores the previous state even if the callback throws, so one
     * estate-wide report cannot leave the rest of the request unscoped.
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->scopeSuspended;
        $this->scopeSuspended = true;

        try {
            return $callback();
        } finally {
            $this->scopeSuspended = $previous;
        }
    }
}
