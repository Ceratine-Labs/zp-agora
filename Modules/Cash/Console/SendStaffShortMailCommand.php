<?php

namespace Modules\Cash\Console;

use Illuminate\Console\Command;
use Modules\Cash\Services\StaffShortService;

/**
 * Send the staff-short mails that have not gone yet.
 *
 * A raise from the screen mails its approvers at once, and a decision its
 * raiser. Two cases are left for this: a send that FAILED (the press is never
 * undone for a mail server — the short is simply left unstamped), and a short
 * raised by a cash-bag match, whose procedure (agora.usp_Recon_ManualMatch)
 * cannot send mail. Safe to run any time: a short already told is stamped on
 * agora.StaffShort and skipped.
 *
 *     php artisan cash:staff-short-mail
 */
class SendStaffShortMailCommand extends Command
{
    protected $signature = 'cash:staff-short-mail';

    protected $description = 'Send the staff-short mails not sent yet (raised → approvers, decided → the raiser).';

    public function handle(StaffShortService $service): int
    {
        $sent = $service->sendPending();

        $this->info("Staff shorts: {$sent['raised']} mail(s) to approvers, {$sent['decided']} to raisers.");

        return self::SUCCESS;
    }
}
