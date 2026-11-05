<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconHelper;
use Modules\StockRecon\Services\StockReconService;

class StockReconHelperController extends Controller
{
    public function __construct(protected StockReconHelper $helper, protected StockReconService $recon) {}

    public function show(StockReconRun $run, Request $request, BranchContext $context): View
    {
        // Binding may run before ResolveBranchContext. Reject stale grants and
        // a different pinned site before reading either run lines or a procedure.
        $this->assertScope($run, $context);
        $run = $this->snapshot($run);
        $this->assertScope($run, $context);
        $input = validator($request->query(), [
            'topic' => ['sometimes', 'string', Rule::in(array_keys(StockReconHelper::TOPICS))],
            'line' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ])->validate();
        $topic = $input['topic'] ?? 'overview';
        $line = isset($input['line']) ? $run->lines->firstWhere('Id', (int) $input['line']) : null;
        abort_if(isset($input['line']) && $line === null, 404);
        if ($line !== null) {
            abort_unless($line->RunId === $run->Id && $line->BranchId === $run->BranchId, 404);
        }
        $lines = $line === null ? collect() : $run->lines->filter(
            fn (StockReconRunLine $candidate) => $candidate->continuityKey() === $line->continuityKey(),
        )->values();
        $chain = $line === null ? ['item' => null, 'shifts' => collect()] : $this->recon->chain($run, $line);
        $choices = $this->helper->choices($run->lines, $topic);
        $choicePages = max(1, (int) ceil($choices->count() / 30));
        $choicePage = min((int) ($input['page'] ?? 1), $choicePages);

        return view('stockrecon::helper', [
            'run' => $run, 'topic' => $topic, 'topics' => StockReconHelper::TOPICS,
            'choices' => $choices->slice(($choicePage - 1) * 30, 30)->values(),
            'choiceCount' => $choices->count(), 'choicePage' => $choicePage, 'choicePages' => $choicePages,
            'line' => $line, 'savedLines' => $lines->keyBy('Id'),
            'explanations' => $this->helper->explain($run, $lines),
            ...$chain,
        ]);
    }

    protected function snapshot(StockReconRun $bound): StockReconRun
    {
        // Reapply the current BranchScope; never trust an earlier binding alone.
        return StockReconRun::query()->with('lines')->findOrFail($bound->getKey());
    }

    private function assertScope(StockReconRun $run, BranchContext $context): void
    {
        abort_unless($context->maySee($run->BranchId), 404);
        abort_if($context->isBranchWorkspace() && $context->id() !== $run->BranchId, 404);
    }
}
