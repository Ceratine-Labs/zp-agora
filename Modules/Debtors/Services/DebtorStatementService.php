<?php

namespace Modules\Debtors\Services;

use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Format;
use App\Support\ProcedureService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Debtors\Mail\DebtorStatementMail;
use Throwable;

/**
 * Statements of account: read one, render it as a PDF, mail it, and the
 * monthly run that mails them all.
 *
 * Every figure comes from agora.usp_Debtors_GetStatement. The screen, the PDF
 * and the mail render the same three result sets, so what somebody checked on
 * the screen is exactly what the debtor receives.
 *
 * Whether a mail reaches a DEBTOR is config('debtors.send_mode') and nothing
 * else: 'off' sends nothing, 'test' sends to the test address, 'live' to the
 * debtor. Every attempt — sent or failed, manual or scheduled — is a row in
 * agora.DebtorStatementSend.
 */
class DebtorStatementService
{
    public function __construct(
        protected ProcedureService $procs,
        protected BranchContext $context,
    ) {}

    public function mode(): string
    {
        $mode = (string) config('debtors.send_mode', 'off');

        return in_array($mode, ['off', 'test', 'live'], true) ? $mode : 'off';
    }

    /**
     * One account's statement for a period.
     *
     * $scoped false is the monthly run, which acts for the business and not
     * for a person, so no site grant narrows it.
     *
     * @return array{account: object, lines: Collection<int, object>, sends: Collection<int, object>}
     */
    public function statement(int $branchId, string $accountNo, ?string $from, ?string $to, bool $scoped = true): array
    {
        $sets = $this->procs->callSets('agora.usp_Debtors_GetStatement', [
            'BranchId' => $branchId,
            'AccountNo' => $accountNo,
            'DateFrom' => $from,
            'DateTo' => $to,
            'AllowedBranchIds' => $scoped ? $this->allowedBranchIds() : null,
        ]);

        return [
            'account' => $sets[0]->first(),
            'lines' => $sets[1] ?? collect(),
            'sends' => $sets[2] ?? collect(),
        ];
    }

    /** @return array{0: string, 1: string} last month, as the grid and the run default it */
    public static function lastMonth(): array
    {
        $to = BusinessDate::today()->startOfMonth()->subDay();

        return [$to->copy()->startOfMonth()->toDateString(), $to->toDateString()];
    }

    /**
     * The statement as a PDF.
     *
     * @param  array{account: object, lines: Collection<int, object>, sends: Collection<int, object>}  $statement
     */
    public function pdf(array $statement): string
    {
        $options = new Options;
        // Nothing remote: the statement is self-contained, and a renderer that
        // fetches URLs from a document is one that can be pointed at anything.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('debtors::pdf.statement', [
            'a' => $statement['account'],
            'lines' => $statement['lines'],
            'issuer' => $this->issuer(),
            'note' => (string) config('debtors.remittance_note', ''),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** "Statement 114 2026-09.pdf" */
    public function filename(object $account): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $account->AccountNo) ?: 'account';

        return 'Statement '.$safe.' '.substr((string) $account->PeriodTo, 0, 7).'.pdf';
    }

    /**
     * Who a statement goes to, in a send mode. The send and the preview both
     * ask this, so the preview cannot show addresses the send would not use.
     *
     * 'off' answers as 'live' would — the preview then shows where the
     * statement WILL go once sending is switched on.
     *
     * @return array{to: array<int, string>, cc: array<int, string>, bcc: array<int, string>, testFor: ?string, problem: ?string}
     */
    public function envelope(object $a, string $mode): array
    {
        $debtor = array_values(array_filter(array_merge(
            [trim((string) $a->Email)],
            array_map('trim', explode(',', (string) $a->CcEmail)),
        )));

        if (trim((string) $a->Email) === '') {
            return ['to' => [], 'cc' => [], 'bcc' => [], 'testFor' => null,
                'problem' => 'The account has no email address. Add one first.'];
        }

        if ($mode === 'test') {
            $testTo = trim((string) config('debtors.test_recipient', ''));

            return ['to' => $testTo === '' ? [] : [$testTo], 'cc' => [], 'bcc' => [],
                'testFor' => implode(', ', $debtor),
                'problem' => $testTo === '' ? 'Test mode, but no test address is set (debtors.test_recipient).' : null];
        }

        return [
            'to' => [array_shift($debtor)],
            'cc' => $debtor,
            'bcc' => array_values(array_filter(array_map('trim', explode(',', (string) config('debtors.bcc', ''))))),
            'testFor' => null,
            'problem' => null,
        ];
    }

    /**
     * The mail exactly as it is sent: covering note, subject and the PDF.
     *
     * @param  array{account: object, lines: Collection<int, object>, sends: Collection<int, object>}  $statement
     */
    public function mail(array $statement, ?string $testFor): DebtorStatementMail
    {
        $a = $statement['account'];

        return new DebtorStatementMail($this->mailDetails($a), $this->pdf($statement), $this->filename($a), $testFor);
    }

    /**
     * Mail one statement, and record that it went or why it did not.
     * `logged` is true when the attempt reached the mail server and was
     * recorded as failed; a refusal before that (no address, sending off) is
     * not recorded.
     *
     * @return array{ok: bool, message: string, logged?: bool}
     */
    public function send(int $branchId, string $accountNo, ?string $from, ?string $to, string $trigger, ?int $userId): array
    {
        $mode = $this->mode();

        if ($mode === 'off') {
            return ['ok' => false, 'message' => 'Sending is switched off (debtors.send_mode). Nothing was mailed.'];
        }

        $statement = $this->statement($branchId, $accountNo, $from, $to, $trigger !== 'schedule');
        $a = $statement['account'];

        $envelope = $this->envelope($a, $mode);

        if ($envelope['problem'] !== null) {
            return ['ok' => false, 'message' => $envelope['problem'].' Nothing was mailed.'];
        }

        ['to' => $to_, 'cc' => $cc, 'bcc' => $bcc] = $envelope;

        $sentTo = implode(', ', array_merge($to_, $cc));
        $status = 'sent';
        $error = null;

        try {
            $mail = $this->mail($statement, $envelope['testFor']);

            $pending = Mail::to($to_);

            if ($cc !== []) {
                $pending->cc($cc);
            }

            if ($bcc !== []) {
                $pending->bcc($bcc);
            }

            $pending->send($mail);
        } catch (Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 400);
            Log::error('agora.debtors.statement-mail-failed', [
                'mail' => DebtorStatementMail::TEMPLATE_CODE, 'branch' => $branchId, 'account' => $accountNo,
                'to' => $sentTo, 'error' => $e->getMessage(),
            ]);
        }

        $this->db()->table(config('agora.schema').'.DebtorStatementSend')->insert([
            'BranchId' => $branchId,
            'AccountNo' => $accountNo,
            'AccountName' => mb_substr((string) $a->AccountName, 0, 60),
            'PeriodFrom' => substr((string) $a->PeriodFrom, 0, 10),
            'PeriodTo' => substr((string) $a->PeriodTo, 0, 10),
            'OpeningBalance' => $a->Opening,
            'ClosingBalance' => $a->Closing,
            'SentTo' => mb_substr($sentTo, 0, 600),
            'Mode' => $mode,
            'Trigger' => $trigger,
            'Status' => $status,
            'Error' => $error,
            'CreatedAt' => now('UTC'),
            'CreatedBy' => $userId,
        ]);

        if ($status !== 'sent') {
            return ['ok' => false, 'logged' => true, 'message' => 'The mail server refused it: '.$error];
        }

        return ['ok' => true, 'message' => $mode === 'test'
            ? "Test statement mailed to {$sentTo} — not to the debtor."
            : "Statement mailed to {$sentTo}."];
    }

    /**
     * Mail the statements of several accounts, one after another. Each is a
     * send() of its own — its own envelope, PDF and row in
     * agora.DebtorStatementSend — so one failure stops nothing else.
     *
     * @param  array<int, array{branch: int, account: string, from: string, to: string}>  $accounts
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendMany(array $accounts, ?int $userId): array
    {
        $tally = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($accounts as ['branch' => $branchId, 'account' => $accountNo, 'from' => $from, 'to' => $to]) {
            try {
                $result = $this->send($branchId, $accountNo, $from, $to, 'manual', $userId);
            } catch (Throwable $e) {
                Log::error('agora.debtors.bulk-send-refused', ['branch' => $branchId, 'account' => $accountNo, 'error' => $e->getMessage()]);
                $tally['skipped']++;

                continue;
            }

            $tally[$result['ok'] ? 'sent' : ($result['logged'] ?? false ? 'failed' : 'skipped')]++;
        }

        Log::info('agora.debtors.bulk-send', $tally + ['accounts' => count($accounts), 'user' => $userId]);

        return $tally;
    }

    /**
     * Of these accounts, the ones with an address to mail.
     *
     * @param  array<int, array{0: int, 1: string}>  $accounts
     * @return array<int, array{0: int, 1: string}>
     */
    public function withAddress(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $have = $this->db()->table(config('agora.schema').'.DebtorContact')
            ->whereNotNull('Email')
            ->whereIn('BranchId', array_unique(array_column($accounts, 0)))
            ->get(['BranchId', 'AccountNo'])
            ->map(fn ($r) => $r->BranchId.'|'.$r->AccountNo)
            ->flip();

        return array_values(array_filter($accounts, fn ($a) => $have->has($a[0].'|'.$a[1])));
    }

    /**
     * The accounts the monthly run would mail for a period: on the run, with
     * an address, something to say, and no statement for this period already
     * sent by the run in the current mode.
     *
     * The rows are agora.usp_Debtors_GridAccounts' — the same rows, and the
     * same "On the monthly run" status, the screen shows.
     *
     * @param  array<int, string|int>  $branchIds  empty for every site
     * @return Collection<int, object>
     */
    public function dueForRun(string $from, string $to, array $branchIds = []): Collection
    {
        $sets = $this->procs->callSets('agora.usp_Debtors_GridAccounts', [
            'BranchIds' => $branchIds === [] ? null : implode(',', $branchIds),
            'DateFrom' => $from,
            'DateTo' => $to,
            'Page' => 1,
            'PageSize' => 100000,
            'FiltersJson' => json_encode([['column' => 'Status', 'type' => 'set', 'in' => ['On the monthly run']]]),
            'AllowedBranchIds' => null,
        ]);

        $already = $this->db()->table(config('agora.schema').'.DebtorStatementSend')
            ->where('PeriodTo', $to)->where('Trigger', 'schedule')->where('Status', 'sent')
            ->where('Mode', $this->mode())
            ->get(['BranchId', 'AccountNo'])
            ->map(fn ($r) => $r->BranchId.'|'.$r->AccountNo)
            ->flip();

        return ($sets[0] ?? collect())
            ->reject(fn ($r) => $already->has($r->BranchId.'|'.$r->AccountNo))
            ->values();
    }

    /** @return array{ok: bool, message: string} */
    public function saveContact(int $branchId, string $accountNo, ?string $email, ?string $cc, bool $autoSend, ?string $note, ?int $userId): array
    {
        $row = $this->procs->write('agora.usp_Debtors_SaveContact', [
            'BranchId' => $branchId,
            'AccountNo' => $accountNo,
            'Email' => $email,
            'CcEmail' => $cc,
            'AutoSend' => $autoSend ? 1 : 0,
            'Note' => $note,
            'UserId' => $userId,
            'AllowedBranchIds' => $this->allowedBranchIds(),
        ]);

        return ['ok' => true, 'message' => (string) $row->Message];
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    public function allowedBranchIds(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }

    /** The group entity, which a statement is issued by. */
    protected function issuer(): string
    {
        return (string) ($this->db()->table(config('agora.schema').'.Branch')
            ->where('BranchId', (int) config('agora.group_branch_id', 2))
            ->value('Name') ?? '');
    }

    /** @return array{site: string, accountNo: string, accountName: string, period: string, periodEnd: string, closing: string, owing: bool} */
    protected function mailDetails(object $a): array
    {
        $to = Carbon::parse((string) $a->PeriodTo);

        return [
            'site' => (string) $a->BranchName,
            'accountNo' => (string) $a->AccountNo,
            'accountName' => (string) $a->AccountName,
            'period' => $to->format('F Y'),
            'periodEnd' => $to->format('j F Y'),
            'closing' => Format::r(abs((float) $a->Closing)),
            'owing' => (float) $a->Closing > 0,
        ];
    }

    protected function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
