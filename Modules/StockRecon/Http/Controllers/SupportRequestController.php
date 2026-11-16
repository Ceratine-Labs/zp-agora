<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\User;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Models\StockReconSupportRequest;
use Modules\StockRecon\Services\StockReconNotifier;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support requests on a chain (Ryan, 16 Nov 2026, after the client meeting):
 * raised from a chain row with its details filled in, a message and an
 * optional annotated screenshot; listed for head office to work through and
 * mark resolved.
 *
 * Permissions are the run's own: who may see a run may raise a request on it
 * and see their sites' log; who may amend (Admin, Finance) may resolve one.
 */
class SupportRequestController extends Controller
{
    private const DISK = 'local';

    private const DIR = 'stockrecon-support';

    /** The modal body: the chain's details, the message, the screenshot tools. */
    public function form(StockReconRun $run, StockReconRunLine $line): View
    {
        [$run, $line] = $this->runLine($run, $line);
        $chain = $this->chain($run, $line);

        return view('stockrecon::partials.support-request', ['run' => $run, 'line' => $line] + $chain);
    }

    /** Raise it. JSON for the modal's script; a redirect without script. */
    public function store(Request $request, StockReconRun $run, StockReconRunLine $line): JsonResponse|RedirectResponse
    {
        [$run, $line] = $this->runLine($run, $line);
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:6144'],
        ]);
        $chain = $this->chain($run, $line);

        $ticket = new StockReconSupportRequest;
        $ticket->forceFill([
            'BranchId' => $run->BranchId,
            'RunId' => $run->Id,
            'RunLineId' => $line->Id,
            'AreaNo' => $line->AreaNo,
            'AreaDescription' => $line->AreaDescription,
            'StockItemNo' => $line->StockItemNo,
            'ItemDescription' => $line->ItemDescription,
            'ChainFrom' => $chain['from'],
            'ChainTo' => $chain['to'],
            'Message' => trim($data['message']),
            'Status' => 'open',
            'CreatedBy' => $request->user()?->Id,
        ])->save();

        if ($request->hasFile('screenshot')) {
            $path = self::DIR.'/'.$run->BranchId.'/'.$ticket->Id.'.'.$request->file('screenshot')->extension();
            Storage::disk(self::DISK)->put($path, (string) $request->file('screenshot')->get());
            $ticket->forceFill(['ScreenshotPath' => $path])->save();
        }

        app(StockReconNotifier::class)->support($run, $ticket);

        $message = 'Support request #'.$ticket->Id.' logged. Head office will see it under Support requests.';

        return $request->expectsJson()
            ? response()->json(['id' => $ticket->Id, 'message' => $message])
            : back()->with('adjusted', $message);
    }

    /** The log: open first, newest first, the sites this person may see. */
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['open', 'resolved', 'all'], true) ? $request->query('status') : 'open';
        $tickets = StockReconSupportRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('Status', $status))
            ->orderByDesc('CreatedAt')
            ->limit(500)
            ->get();
        $people = User::query()->acrossBranches()
            ->whereIn('Id', $tickets->pluck('CreatedBy')->merge($tickets->pluck('ResolvedBy'))->filter()->unique())
            ->pluck('UserName', 'Id');

        return view('stockrecon::support.index', [
            'tickets' => $tickets,
            'people' => $people,
            'status' => $status,
            'counts' => StockReconSupportRequest::query()->selectRaw('Status, COUNT(*) AS N')->groupBy('Status')->pluck('N', 'Status'),
        ]);
    }

    public function resolve(Request $request, StockReconSupportRequest $ticket): RedirectResponse
    {
        $ticket = StockReconSupportRequest::query()->findOrFail($ticket->getKey());
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $ticket->forceFill([
            'Status' => 'resolved',
            'ResolvedAt' => now(),
            'ResolvedBy' => $request->user()?->Id,
            'ResolutionNote' => isset($data['note']) ? trim($data['note']) : null,
            'UpdatedBy' => $request->user()?->Id,
        ])->save();

        return back()->with('resolved', 'Support request #'.$ticket->Id.' marked resolved.');
    }

    public function screenshot(StockReconSupportRequest $ticket): StreamedResponse
    {
        $ticket = StockReconSupportRequest::query()->findOrFail($ticket->getKey());
        abort_unless($ticket->ScreenshotPath && Storage::disk(self::DISK)->exists($ticket->ScreenshotPath), 404);

        return Storage::disk(self::DISK)->response($ticket->ScreenshotPath, 'support-'.$ticket->Id.'.'.pathinfo($ticket->ScreenshotPath, PATHINFO_EXTENSION));
    }

    /**
     * The chain's period: its first and last shift on the run.
     *
     * @return array{from: Carbon, to: Carbon, shifts: int}
     */
    private function chain(StockReconRun $run, StockReconRunLine $line): array
    {
        $lines = StockReconRunLine::query()
            ->where('RunId', $run->Id)->where('AreaNo', $line->AreaNo)->where('StockItemNo', $line->StockItemNo)
            ->where('ContinuitySegment', $line->ContinuitySegment)
            ->get(['TransactionDate']);

        return [
            'from' => $lines->min('TransactionDate') ?? $line->TransactionDate,
            'to' => $lines->max('TransactionDate') ?? $line->TransactionDate,
            'shifts' => $lines->count(),
        ];
    }

    /** @return array{0: StockReconRun, 1: StockReconRunLine} */
    private function runLine(StockReconRun $run, StockReconRunLine $line): array
    {
        $run = StockReconRun::query()->findOrFail($run->getKey());
        $line = StockReconRunLine::query()->findOrFail($line->getKey());
        abort_unless($line->RunId === $run->Id && $line->BranchId === $run->BranchId, 404);

        return [$run, $line];
    }
}
