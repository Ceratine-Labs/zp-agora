<?php

namespace Modules\StockRecon\Services;

use App\Support\Format;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Models\Branch;
use Modules\StockRecon\Mail\AdjustmentLoggedMail;
use Modules\StockRecon\Mail\SupportLoggedMail;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Models\StockReconSupportRequest;
use Modules\StockRecon\Support\ShiftLabel;

/**
 * Mail to config('stockrecon.notify') when something is logged on a run
 * (Ryan, 16 Nov 2026: "send me an email on my RevTech address whenever
 * there's an edit logged or a support call"): a closing adjusted by hand, and
 * a support request. A mail that fails is reported and never undoes or
 * blocks what was logged.
 */
class StockReconNotifier
{
    public function adjustment(StockReconRun $run, StockReconRunLine $line, int $adjustmentId): void
    {
        $this->send(function () use ($run, $line, $adjustmentId) {
            $adj = DB::connection(config('agora.connections.app'))
                ->table(config('agora.schema').'.StockReconManualAdjustment')->where('Id', $adjustmentId)->first();
            if ($adj === null) {
                return null;
            }

            return new AdjustmentLoggedMail((int) $run->BranchId, [
                'adj' => [
                    'run' => (string) $run->Id,
                    'site' => $this->site((int) $run->BranchId),
                    'area' => (string) ($line->AreaDescription ?? 'Area '.$line->AreaNo),
                    'item' => trim(($line->ItemDescription ?? '').' ('.$line->StockItemNo.')'),
                    'shift' => Carbon::parse($adj->TransactionDate)->format('j M Y').', shift '.ShiftLabel::show((int) $adj->ShiftNo),
                    'counted' => Format::qty($adj->CountedClose),
                    'prior' => Format::qty($adj->PriorCloseNew),
                    'new' => Format::qty($adj->NewClose),
                    'reason' => (string) $adj->Reason,
                    'by' => (string) (auth()->user()->UserName ?? 'Unknown'),
                ],
                'url' => route('app.stockrecon.run', $run),
            ]);
        });
    }

    public function support(StockReconRun $run, StockReconSupportRequest $ticket): void
    {
        $this->send(fn () => new SupportLoggedMail((int) $ticket->BranchId, [
            'ticket' => [
                'id' => (string) $ticket->Id,
                'site' => $this->site((int) $ticket->BranchId),
                'area' => (string) ($ticket->AreaDescription ?? 'Area '.$ticket->AreaNo),
                'item' => trim(($ticket->ItemDescription ?? '').' ('.$ticket->StockItemNo.')'),
                'chain' => $ticket->ChainFrom->format('j M').' – '.$ticket->ChainTo->format('j M Y'),
                'run' => (string) $ticket->RunId,
                'by' => (string) (auth()->user()->UserName ?? 'Unknown'),
                'screenshot' => $ticket->ScreenshotPath ? 'Attached on the request' : 'None',
                'message' => (string) $ticket->Message,
            ],
            'url' => route('app.stockrecon.support.index'),
            'runUrl' => route('app.stockrecon.run', $run),
        ]));
    }

    /** @param callable(): (Mailable|null) $build */
    private function send(callable $build): void
    {
        $to = array_values(array_filter((array) config('stockrecon.notify', [])));
        if ($to === []) {
            return;
        }

        try {
            $mail = $build();
            if ($mail !== null) {
                Mail::to($to)->send($mail);
            }
        } catch (\Throwable $e) {
            Log::warning('Stock recon notification not sent: '.$e->getMessage());
            report($e);
        }
    }

    private function site(int $branchId): string
    {
        return (string) (Branch::query()->acrossBranches()->where('BranchId', $branchId)->value('Name') ?? 'Site '.$branchId);
    }
}
