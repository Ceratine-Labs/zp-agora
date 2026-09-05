<?php

namespace Modules\Core\Console;

use App\Support\Format;
use App\Support\ProcedureService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Runs `agora.usp_Core_MigrateUsers` and puts its two result sets on screen.
 *
 * A DRY RUN by default. `--apply` is the only thing that writes, and it says
 * so in the summary line afterwards, because "did that actually do anything"
 * is the question somebody asks five minutes later.
 *
 *     php artisan agora:migrate-users              # report only
 *     php artisan agora:migrate-users --apply
 *     php artisan agora:migrate-users --only=skip  # just what did not come across
 *
 * The report is the deliverable, not the row count. 85 legacy users do not
 * become 85 Agora users: some share an address, some have none, and the
 * unique key on (BranchId, EmailAddress) means one of a duplicate pair has to
 * lose. The `--only=skip` view is the list to send back to the customer.
 */
class MigrateUsersCommand extends Command
{
    protected $signature = 'agora:migrate-users
        {--apply : Write the rows. Without this nothing is changed}
        {--branch= : The branch the users belong to; defaults to the group entity}
        {--only= : Show only these rows — migrate, update or skip}';

    protected $description = 'Migrate dbo.SS_Users into agora.User, or report on what would happen';

    public function handle(ProcedureService $procedures): int
    {
        $branchId = (int) ($this->option('branch') ?: config('agora.group_branch_id'));
        $apply = (bool) $this->option('apply');

        $sets = $procedures->callSets('usp_Core_MigrateUsers', [
            'BranchId' => $branchId,
            'Apply' => $apply,
        ]);

        /** @var Collection<int, object> $report */
        $report = $sets[0] ?? collect();
        $summary = ($sets[1] ?? collect())->first();

        $only = $this->option('only');
        $rows = $only ? $report->where('Action', $only) : $report;

        if ($rows->isEmpty()) {
            $this->components->warn('No rows to show.');
        } else {
            /** @var array<int, array<int, string>> $lines */
            $lines = [];

            foreach ($rows as $row) {
                $lines[] = [
                    (string) $row->LegacyUserId,
                    (string) $row->UserName,
                    // An address that is not there is an em dash, not an empty
                    // cell — the difference is the whole point of the report.
                    (string) ($row->EmailAddress ?? Format::NOTHING),
                    (string) ($row->LegacyUserType ?? Format::NOTHING),
                    (string) $row->UserType,
                    $row->IsActive ? 'yes' : 'no',
                    (string) $row->Action,
                    (string) $row->Reason,
                ];
            }

            $this->table(
                ['Legacy', 'Name', 'Email', 'Legacy type', 'Type', 'Active', 'Action', 'Why'],
                $lines,
            );
        }

        if (! $summary) {
            $this->components->error('The procedure returned no summary. That is a fault, not a refusal.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('mode', $apply ? 'APPLIED — rows were written' : 'dry run — nothing written');
        $this->components->twoColumnDetail('branch', (string) $summary->BranchId);
        $this->components->twoColumnDetail('legacy users considered', (string) $summary->Considered);
        $this->components->twoColumnDetail('new Agora users', (string) $summary->Migrated);
        $this->components->twoColumnDetail('existing users refreshed', (string) $summary->Updated);
        $this->components->twoColumnDetail('unmatched (no address, or no @)', (string) $summary->Unmatched);
        $this->components->twoColumnDetail('duplicate addresses skipped', (string) $summary->Duplicate);
        $this->components->twoColumnDetail('blocked by a deleted Agora user', (string) $summary->Blocked);
        $this->components->twoColumnDetail('legacy user type unmapped', (string) $summary->UnmappedUserType);
        $this->newLine();

        if (! $apply) {
            $this->components->info('Nothing was written. Re-run with --apply when the report reads correctly.');
        }

        return self::SUCCESS;
    }
}
