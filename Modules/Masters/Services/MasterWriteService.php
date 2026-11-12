<?php

namespace Modules\Masters\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Masters\Grids\ReferenceMasterGrid;

/**
 * The reference masters' write-backs to PumpIT (T022; CLAUDE.md rule 1, PM
 * decision 01a1cecf-8e08). Every rule is in the master's save procedure; this
 * passes it the values the definition says are editable, what the form showed
 * (the stale check), and the caller's identity, permission and sites —
 * resolved here, never taken from the form — and the literal switch
 * config('masters.write_mode').
 */
class MasterWriteService
{
    public function __construct(
        private ProcedureService $procedures,
        private BranchContext $context,
    ) {}

    /** Read from the LITERAL config('masters.write_mode') and nothing else. */
    public static function writeMode(): string
    {
        return config('masters.write_mode') === 'live' ? 'live' : 'off';
    }

    public static function live(): bool
    {
        return self::writeMode() === 'live';
    }

    /** Whether this caller may edit this master now: it writes, writes are on, and they hold the permission. */
    public static function canEdit(ReferenceMasterGrid $definition): bool
    {
        return $definition->writeEntity() !== null && self::live() && Gate::allows($definition->editPermission());
    }

    /** @param  array<string, mixed>  $input */
    public function save(ReferenceMasterGrid $definition, int $branch, string $key, array $input): object
    {
        $parameters = ['BranchId' => $branch, 'Key' => $key];

        foreach ($definition->editableFields() as $name => $field) {
            $parameters[$name] = $this->value($field, $input[$name] ?? null);
            $parameters['Expected'.$name] = $this->value($field, $input['Expected'.$name] ?? null);
        }

        return $this->procedures->write((string) $definition->saveProcedure(), $parameters + [
            'Reason' => $input['Reason'] ?? null,
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) Gate::allows($definition->editPermission()),
            'WriteMode' => self::writeMode(),
            'AllowedBranchIds' => $definition->perBranch() ? $this->allowedCsv() : null,
            'ReasonMin' => (int) config('masters.reason_min', 10),
        ]);
    }

    public function reverse(ReferenceMasterGrid $definition, int $branch, string $writeId, string $reason): object
    {
        /** @var array<string, class-string> $grids */
        $grids = (array) config('grids.grids', []);
        $entities = collect($grids)
            ->map(fn (string $class) => app($class))
            ->filter(fn ($grid) => $grid instanceof ReferenceMasterGrid && $grid->writeEntity() !== null && Gate::allows($grid->editPermission()))
            ->map(fn (ReferenceMasterGrid $grid) => $grid->writeEntity())
            ->unique()->implode(',');

        return $this->procedures->write('agora.usp_Masters_ReverseWrite', [
            'BranchId' => $branch,
            'WriteId' => $writeId,
            'Reason' => $reason,
            'UserId' => Auth::user()?->Id,
            'AuthorizedEntities' => $entities,
            'WriteMode' => self::writeMode(),
            'AllowedBranchIds' => $definition->perBranch() ? $this->allowedCsv() : null,
            'ReasonMin' => (int) config('masters.reason_min', 10),
        ]);
    }

    /**
     * What has been written to PumpIT for one record, newest first, grouped
     * into presses (one WriteId each).
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function writes(ReferenceMasterGrid $definition, object $row): Collection
    {
        if ($definition->writeEntity() === null) {
            return collect();
        }

        return $this->procedures->call('agora.usp_Masters_GetWrites', [
            'BranchId' => (int) $row->BranchId,
            'Entity' => $definition->writeEntity(),
            'RowKey' => (string) $row->RowKey,
            'AllowedBranchIds' => $definition->perBranch() ? $this->allowedCsv() : null,
        ])->groupBy(fn (object $w) => strtolower((string) $w->WriteId));
    }

    /** @param  array<string, mixed>  $field */
    private function value(array $field, mixed $value): mixed
    {
        return match ($field['type'] ?? 'text') {
            'bool' => (int) (bool) $value,
            'decimal' => is_numeric($value) ? (string) $value : null,
            default => $value === null ? null : trim((string) $value),
        };
    }

    private function allowedCsv(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
