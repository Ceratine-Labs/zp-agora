<?php

namespace Modules\Debtors\Console;

use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Debtors\Services\DebtorStatementService;

/**
 * The monthly statement run.
 *
 * Mails last month's statement to every account on the monthly run that has
 * something to say for it — a balance either side of the month, or a posting
 * in it. Safe to run any time and as often as you like: an account whose
 * statement for the period already went, by this run, is skipped.
 *
 * Whether anything reaches a debtor is config('debtors.send_mode'): 'off' sends
 * nothing and says so, 'test' sends every statement to the test address.
 *
 *     php artisan debtors:send-statements                       last month
 *     php artisan debtors:send-statements --to=2026-09-30       the month ending then
 *     php artisan debtors:send-statements --branch=25 --dry-run who would get one
 */
class SendDebtorStatementsCommand extends Command
{
    protected $signature = 'debtors:send-statements
        {--to= : the last day of the statement period (default: the end of last month)}
        {--branch=* : only these sites}
        {--dry-run : list who would get a statement, send nothing}';

    protected $description = 'Mail last month\'s statement to every debtor on the monthly run.';

    public function handle(DebtorStatementService $service): int
    {
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : BusinessDate::today()->startOfMonth()->subDay()->startOfDay();
        $from = $to->copy()->startOfMonth();

        /** @var array<int, string> $branches */
        $branches = array_values(array_filter((array) $this->option('branch')));
        $due = $service->dueForRun($from->toDateString(), $to->toDateString(), $branches);

        $this->info("Statements for {$from->toDateString()} to {$to->toDateString()}: {$due->count()} account(s) due.");

        if ($this->option('dry-run')) {
            foreach ($due as $row) {
                $this->line("  {$row->BranchName} · {$row->AccountNo} {$row->AccountName} → {$row->Email}");
            }

            return self::SUCCESS;
        }

        $mode = $service->mode();

        if ($mode === 'off') {
            $this->warn('debtors.send_mode is off: nothing was sent.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $row) {
            $result = $service->send(
                (int) $row->BranchId, (string) $row->AccountNo,
                $from->toDateString(), $to->toDateString(), 'schedule', null,
            );

            $result['ok'] ? $sent++ : $failed++;

            if (! $result['ok']) {
                $this->warn("  {$row->BranchName} · {$row->AccountNo}: {$result['message']}");
            }
        }

        $this->info("Sent {$sent}, failed {$failed} ({$mode} mode).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
