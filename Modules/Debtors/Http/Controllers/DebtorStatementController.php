<?php

namespace Modules\Debtors\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Modules\Debtors\Grids\DebtorAccountGrid;
use Modules\Debtors\Http\Requests\SaveContactRequest;
use Modules\Debtors\Services\DebtorStatementService;

/**
 * Debtors statements: the list, one statement, its PDF, sending it, and the
 * address it goes to.
 *
 * Every figure is agora.usp_Debtors_GetStatement's; this controller only
 * chooses which account and which period.
 */
class DebtorStatementController extends Controller
{
    public function __construct(
        protected DebtorStatementService $statements,
        protected GridService $grids,
    ) {}

    public function index(Request $request, DebtorAccountGrid $grid): View
    {
        // Last month, in the date range itself, so the period being stated is
        // on the screen rather than implied by the procedure's default.
        if (! $request->filled('from') && ! $request->filled('to')) {
            [$from, $to] = DebtorStatementService::lastMonth();
            $request->query->add(['from' => $from, 'to' => $to]);
        }

        return view('debtors::index', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
            'mode' => $this->statements->mode(),
        ]);
    }

    public function show(Request $request, int $branch, string $account): View|RedirectResponse
    {
        [$from, $to] = $this->period($request);

        try {
            $statement = $this->statements->statement($branch, $account, $from, $to);
        } catch (AgoraProcException $e) {
            return redirect()->route('app.debtors.index')->withErrors(['refusal' => $e->getMessage()]);
        }

        return view('debtors::show', $statement + [
            'mode' => $this->statements->mode(),
            'testRecipient' => (string) config('debtors.test_recipient', ''),
        ]);
    }

    public function pdf(Request $request, int $branch, string $account): Response|RedirectResponse
    {
        [$from, $to] = $this->period($request);

        try {
            $statement = $this->statements->statement($branch, $account, $from, $to);
        } catch (AgoraProcException $e) {
            return redirect()->route('app.debtors.index')->withErrors(['refusal' => $e->getMessage()]);
        }

        $filename = $this->statements->filename($statement['account']);

        return response($this->statements->pdf($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }

    /**
     * The mail as it would go out: recipients, subject, covering note and the
     * PDF. Built by the same two service methods the send uses; nothing is
     * sent and nothing is recorded.
     */
    public function preview(Request $request, int $branch, string $account): View|RedirectResponse
    {
        [$from, $to] = $this->period($request);

        try {
            $statement = $this->statements->statement($branch, $account, $from, $to);
        } catch (AgoraProcException $e) {
            return redirect()->route('app.debtors.index')->withErrors(['refusal' => $e->getMessage()]);
        }

        $mode = $this->statements->mode();
        $envelope = $this->statements->envelope($statement['account'], $mode);
        $mail = $this->statements->mail($statement, $envelope['testFor']);

        return view('debtors::preview', [
            'account' => $statement['account'],
            'mode' => $mode,
            'envelope' => $envelope,
            'subject' => $mail->envelope()->subject,
            'body' => $mail->render(),
            'filename' => $this->statements->filename($statement['account']),
        ]);
    }

    public function send(Request $request, int $branch, string $account): RedirectResponse
    {
        [$from, $to] = $this->period($request);

        try {
            $result = $this->statements->send($branch, $account, $from, $to, 'manual', $request->user()?->Id);
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return $result['ok']
            ? back()->with('status', $result['message'])
            : back()->withErrors(['refusal' => $result['message']]);
    }

    public function saveContact(SaveContactRequest $request, int $branch, string $account): RedirectResponse
    {
        try {
            $result = $this->statements->saveContact(
                $branch, $account,
                $request->input('email'), $request->input('cc_email'),
                $request->boolean('auto_send'), $request->input('note'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return back()->with('status', $result['message']);
    }

    /** @return array{0: ?string, 1: ?string} the period asked for, or null for the procedure's default */
    private function period(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key, $request->input($key, '')))
            ? (string) $request->query($key, $request->input($key))
            : null;

        return [$date('from'), $date('to')];
    }
}
