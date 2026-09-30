<?php

namespace Modules\Cash\Services;

use App\Support\Format;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Cash\Mail\StaffShortDecidedMail;
use Modules\Cash\Mail\StaffShortRaisedMail;
use Modules\Cash\Support\StaffShortScope;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Services\PermissionService;
use Throwable;

/**
 * Staff shorts (T040): the seam between the screens and the procedures, and
 * the two mails.
 *
 * Every rule is T-SQL — what a short may be raised for, who it may be raised
 * against, when it may be decided or posted — and every figure comes off
 * agora.vw_CashStaffShort through agora.usp_Cash_GetStaffShorts. What this class
 * adds is only what a procedure cannot know: where the settings are, who the
 * person is, whether the literal write switches are on, and who to tell.
 *
 * WHAT REACHES THE CUSTOMER'S DATABASE, and only through a procedure, only
 * while writeMode() is 'live':
 *   raise()    usp_Cash_RaiseStaffShort     BRN_StaffShorts (INSERT),
 *                                           SS_UniqueNumber section 2 (UPDATE)
 *   approve()  usp_Cash_ApproveStaffShort   BRN_StaffShorts (Approved, ApprovedDate,
 *                                           ApprovedByUserId)
 *   decline()  usp_Cash_DeclineStaffShort   BRN_StaffShorts (IsDeclined,
 *                                           DeclinedReason, DeclinedByUserId,
 *                                           DeclinedDate)
 *   withdraw() usp_Cash_WithdrawStaffShort  BRN_StaffShorts (Approved,
 *                                           ApprovedDate, ApprovedByUserId back
 *                                           to 0 / NULL / 0; IsDeclined,
 *                                           DeclinedReason, DeclinedByUserId,
 *                                           DeclinedDate) — an approved short
 *                                           not yet on a cashup
 *   post()     usp_Cash_PostStaffShort      the cashup's StaffShorts leg through
 *                                           usp_Cash_SaveCashupLeg: BRN_DailyBankingEmployees
 *                                           (INSERT), BRN_StaffShorts
 *                                           (IsSelectedForDailyBanking), the
 *                                           BRN_DailyBanking header's totals —
 *                                           also only while cash.cashup.write_mode
 *                                           is 'live'
 *
 * THE MAILS (T040's acceptance: raising sends the approver an email). A raise
 * mails every APPROVER of the site — a person granted cash.staffshort.approve
 * by a grant in the cash module (the permission, cash.staffshort.*, cash.*.*),
 * active, with an address, whose branch grants include the site (none means
 * every site). An administrator's *.*.* lets them approve but does not put
 * them on the list: they would be mailed about every short in the estate. A
 * decision mails the person who raised the short. A send that fails is logged
 * and left unstamped, so `php artisan cash:staff-short-mail` sends it later —
 * a broken mail server never undoes a press that already wrote PumpIT.
 */
class StaffShortService
{
    public function __construct(
        protected ProcedureService $procedures,
        protected PermissionService $permissions,
    ) {}

    /**
     * 'live' when a press may write PumpIT; 'off' otherwise.
     *
     * Read from the LITERAL config('cash.staffshort.write_mode') and nothing
     * else, and anything that is not exactly 'live' is 'off', so a typo fails
     * safe.
     */
    public function writeMode(): string
    {
        return config('cash.staffshort.write_mode') === 'live' ? 'live' : 'off';
    }

    /** Posting writes the cashup's leg, so the cashup's own switch has to be on too. */
    public function postMode(): string
    {
        return $this->writeMode() === 'live' && config('cash.cashup.write_mode') === 'live' ? 'live' : 'off';
    }

    /**
     * One site: its shorts (the open ones whatever their date, the rest in the
     * period), the reasons and people a raise chooses from, the totals, and
     * the trail of the short opened.
     *
     * @return array{shorts: Collection<int, object>, reasons: Collection<int, object>, employees: Collection<int, object>,
     *               summary: ?object, trail: Collection<int, object>, tills: Collection<int, object>,
     *               shifts: Collection<int, object>}
     */
    public function site(StaffShortScope $scope): array
    {
        $sets = $this->procedures->callSets('usp_Cash_GetStaffShorts', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
            'StaffShortsNo' => $scope->shortNo,
        ]);

        return [
            'shorts' => $sets[0] ?? collect(),
            'reasons' => $sets[1] ?? collect(),
            'employees' => $sets[2] ?? collect(),
            'summary' => ($sets[3] ?? collect())->first(),
            'trail' => $sets[4] ?? collect(),
            'tills' => $sets[5] ?? collect(),
            'shifts' => $sets[6] ?? collect(),
        ];
    }

    /**
     * Raise a short — PumpIT is written — and tell the approvers.
     *
     * @param  array{employee: string, amount: string, day: string, reason: string, note: ?string, till: ?int, shift: ?int}  $short
     */
    public function raise(int $branchId, array $short, int $userId, string $source = 'screen'): object
    {
        $status = $this->procedures->write('usp_Cash_RaiseStaffShort', [
            'BranchId' => $branchId,
            'EmployeeCode' => $short['employee'],
            'AmountShort' => $short['amount'],
            'TransactionDate' => $short['day'],
            'ReasonCode' => $short['reason'],
            'Note' => $short['note'],
            'TillNo' => $short['till'],
            'ShiftNo' => $short['shift'],
            'Source' => $source === 'phone' ? 'phone' : 'screen',
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);

        $this->mailRaised($branchId, (int) $status->StaffShortsNo);

        return $status;
    }

    /** The approver's yes — PumpIT is written — and the raiser is told. */
    public function approve(int $branchId, int $shortNo, int $userId, ?string $note = null): object
    {
        $status = $this->procedures->write('usp_Cash_ApproveStaffShort', [
            'BranchId' => $branchId,
            'StaffShortsNo' => $shortNo,
            'Note' => $note,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);

        $this->mailDecided($branchId, $shortNo);

        return $status;
    }

    /** The approver's no, with why — PumpIT is written — and the raiser is told. */
    public function decline(int $branchId, int $shortNo, ?string $reason, int $userId): object
    {
        $status = $this->procedures->write('usp_Cash_DeclineStaffShort', [
            'BranchId' => $branchId,
            'StaffShortsNo' => $shortNo,
            'Reason' => $reason,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);

        $this->mailDecided($branchId, $shortNo);

        return $status;
    }

    /**
     * Take an approved short back before it is on a cashup — PumpIT is
     * written (the approval undone, the short declined) — and the raiser is
     * told it was withdrawn.
     */
    public function withdraw(int $branchId, int $shortNo, ?string $reason, int $userId): object
    {
        $status = $this->procedures->write('usp_Cash_WithdrawStaffShort', [
            'BranchId' => $branchId,
            'StaffShortsNo' => $shortNo,
            'Reason' => $reason,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);

        $this->mailDecided($branchId, $shortNo);

        return $status;
    }

    /**
     * Put an approved short on a cashup's pump shortages — PumpIT is written
     * (the cashup's leg, the short's flag, the header's totals). No cashup
     * named means the one it was raised for.
     */
    public function post(int $branchId, int $shortNo, ?string $day, ?int $tillNo, ?int $shiftNo, int $userId): object
    {
        return $this->procedures->write('usp_Cash_PostStaffShort', [
            'BranchId' => $branchId,
            'StaffShortsNo' => $shortNo,
            'TransactionDate' => $day,
            'TillNo' => $tillNo,
            'ShiftNo' => $shiftNo,
            'WriteMode' => $this->postMode(),
            'UserId' => $userId,
        ]);
    }

    /**
     * Who is told a short was raised at a site. See the class header.
     *
     * @return Collection<int, User>
     */
    public function approvers(int $branchId): Collection
    {
        $users = User::query()->acrossBranches()
            ->where('IsActive', true)->where('IsLocked', false)
            ->where('EmailAddress', 'like', '%@%')
            ->get();

        $grants = UserBranch::query()->acrossBranches()
            ->whereIn('UserId', $users->pluck('Id')->all() ?: [0])
            ->get(['UserId', 'BranchId'])
            ->groupBy('UserId');

        return $users->filter(function (User $user) use ($branchId, $grants): bool {
            $cash = array_values(array_filter(
                $this->permissions->patternsFor($user),
                fn (string $pattern): bool => str_starts_with($pattern, 'cash.') || $pattern === 'cash',
            ));

            if (! $this->permissions->anyMatches($cash, 'cash.staffshort.approve')) {
                return false;
            }

            $sites = ($grants[$user->Id] ?? collect())->pluck('BranchId')->map(fn ($id) => (int) $id);

            return $sites->isEmpty() || $sites->contains($branchId);
        })->values();
    }

    /**
     * Tell the site's approvers a short is waiting. Returns how many mails
     * went. Stamped on agora.StaffShort when every send went (or there was
     * nobody to tell); left unstamped for cash:staff-short-mail otherwise.
     */
    public function mailRaised(int $branchId, int $shortNo): int
    {
        $short = $this->short($branchId, $shortNo);

        if ($short === null || $short->AgoraShortId === null || $short->RaisedMailAt !== null) {
            return 0;
        }

        $details = $this->details($short) + [
            'raisedBy' => $this->userName($short->RaisedByUserId) ?? 'Agora',
            'source' => $short->Source === 'recon' ? ($short->SourceRef ?? 'a cash-bag match') : null,
        ];

        $sent = 0;
        $failed = 0;
        $approvers = $this->approvers($branchId);

        foreach ($approvers as $approver) {
            try {
                Mail::to((string) $approver->EmailAddress)->send(
                    new StaffShortRaisedMail($details, (string) $approver->UserName, $this->url($branchId, $shortNo))
                );
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('agora.staffshort.mail-failed', [
                    'mail' => StaffShortRaisedMail::TEMPLATE_CODE, 'short' => $shortNo, 'to' => $approver->EmailAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failed === 0) {
            $this->db()->table(config('agora.schema').'.StaffShort')
                ->where('BranchId', $branchId)->where('StaffShortsNo', $shortNo)
                ->update(['RaisedMailAt' => now(), 'RaisedMailTo' => $sent]);
        }

        return $sent;
    }

    /**
     * Tell the person who raised a short that it was decided. Returns how many
     * mails went (0 or 1). A short raised in PumpIT is told to its raiser too
     * when PumpIT's user has an Agora account; nothing is stamped for it,
     * because Agora keeps no record of a short it did not raise.
     */
    public function mailDecided(int $branchId, int $shortNo): int
    {
        $short = $this->short($branchId, $shortNo);

        if ($short === null || ! in_array($short->Status, ['Approved', 'Declined', 'Posted'], true)
            || $short->DecidedMailAt !== null) {
            return 0;
        }

        $raiser = $short->RaisedByUserId !== null
            ? User::query()->acrossBranches()->find($short->RaisedByUserId)
            : User::query()->acrossBranches()->where('LegacyUserId', $short->CreatedByUserId)->first();

        $decision = $this->db()->table(config('agora.schema').'.StaffShortEvent')
            ->where('BranchId', $branchId)->where('StaffShortsNo', $shortNo)
            ->whereIn('Event', ['approved', 'declined', 'withdrawn'])
            ->orderByDesc('Id')->first();

        $sent = 0;
        $failed = false;

        if ($raiser !== null && $raiser->IsActive && str_contains((string) $raiser->EmailAddress, '@')) {
            $declined = (bool) $short->IsDeclined;
            $details = $this->details($short) + [
                'decision' => ($decision->Event ?? null) === 'withdrawn' ? 'withdrawn' : ($declined ? 'declined' : 'approved'),
                'decidedBy' => $this->userName($decision->CreatedBy ?? null),
                'why' => $declined ? ($decision->Note ?? $short->DeclinedReason) : ($decision->Note ?? null),
            ];

            try {
                Mail::to((string) $raiser->EmailAddress)->send(
                    new StaffShortDecidedMail($details, (string) $raiser->UserName, $this->url($branchId, $shortNo))
                );
                $sent = 1;
            } catch (Throwable $e) {
                $failed = true;
                Log::error('agora.staffshort.mail-failed', [
                    'mail' => StaffShortDecidedMail::TEMPLATE_CODE, 'short' => $shortNo, 'to' => $raiser->EmailAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $failed && $short->AgoraShortId !== null) {
            $this->db()->table(config('agora.schema').'.StaffShort')
                ->where('BranchId', $branchId)->where('StaffShortsNo', $shortNo)
                ->update(['DecidedMailAt' => now()]);
        }

        return $sent;
    }

    /**
     * Send every mail not sent yet: a short raised with no approver told (a
     * failed send, or one raised by a cash-bag match, whose procedure cannot
     * send mail), and a decision its raiser has not heard about.
     *
     * @return array{raised: int, decided: int}
     */
    public function sendPending(): array
    {
        $schema = config('agora.schema');
        $out = ['raised' => 0, 'decided' => 0];

        foreach ($this->db()->table("{$schema}.StaffShort")->whereNull('RaisedMailAt')->orderBy('Id')->get(['BranchId', 'StaffShortsNo']) as $row) {
            $out['raised'] += $this->mailRaised((int) $row->BranchId, (int) $row->StaffShortsNo);
        }

        foreach ($this->db()->table("{$schema}.StaffShort")->whereNull('DecidedMailAt')->orderBy('Id')->get(['BranchId', 'StaffShortsNo']) as $row) {
            $out['decided'] += $this->mailDecided((int) $row->BranchId, (int) $row->StaffShortsNo);
        }

        return $out;
    }

    /** The tone a status reads in. */
    public static function statusTone(?string $status): string
    {
        return match ($status) {
            'Waiting' => 'warn',
            'Approved' => 'serious',
            'Posted' => 'good',
            'Declined' => 'neutral',
            default => 'neutral',
        };
    }

    /** What a status means, in words, for the person reading the row. */
    public static function statusWords(?string $status): string
    {
        return match ($status) {
            'Waiting' => 'waiting for an approver',
            'Approved' => 'approved — not on a cashup yet',
            'Posted' => 'on a cashup',
            'Declined' => 'declined — not charged',
            default => (string) $status,
        };
    }

    private function short(int $branchId, int $shortNo): ?object
    {
        return $this->db()->table(config('agora.schema').'.vw_CashStaffShort')
            ->where('BranchId', $branchId)->where('StaffShortsNo', $shortNo)->first();
    }

    /**
     * @return array{no: int, site: string, employee: string, code: string, amount: string, reason: string, day: string, note: ?string}
     */
    private function details(object $short): array
    {
        return [
            'no' => (int) $short->StaffShortsNo,
            'site' => (string) (Branch::query()->acrossBranches()->where('BranchId', $short->BranchId)->value('Name') ?? 'site '.$short->BranchId),
            'employee' => (string) ($short->EmployeeName ?: $short->EmployeeCode),
            'code' => (string) $short->EmployeeCode,
            'amount' => Format::r((float) $short->AmountShort),
            'reason' => (string) $short->ReasonLabel,
            'day' => Carbon::parse((string) $short->TransactionDate)->format('j M Y'),
            'note' => $short->Note,
        ];
    }

    private function userName(mixed $userId): ?string
    {
        return $userId === null ? null : User::query()->acrossBranches()->whereKey((int) $userId)->value('UserName');
    }

    private function url(int $branchId, int $shortNo): string
    {
        return route('app.cash.staffshorts', ['branch_id' => $branchId, 'short' => $shortNo]);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
