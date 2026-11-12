<?php

namespace Modules\Partners\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Modules\Partners\Http\Requests\AddSupplierRequest;
use Modules\Partners\Http\Requests\ReverseWriteRequest;
use Modules\Partners\Http\Requests\SaveSupplierRequest;
use Modules\Partners\Services\PartnerService;
use Modules\Partners\Services\PartnerWriteService;

/**
 * Setup → Trading rules → Suppliers (T024): change a supplier, add one, and
 * put a change back — a NAMED WRITE to PumpIT's BRN_Suppliers (CLAUDE.md
 * rule 1, PM decision 01a1cecf-8e08). Every rule is in the procedures; with
 * partners.write_mode off these routes do not exist (404).
 */
class SupplierEditController extends Controller
{
    public function __construct(private PartnerService $reads, private PartnerWriteService $writes) {}

    public function create(): View
    {
        $this->guard();

        return view('partners::suppliers.create', ['next' => $this->writes->nextSupplierCodes()]);
    }

    public function store(AddSupplierRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->addSupplier($request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        // The new code at every site it was added at.
        return redirect()->route('app.setup.suppliers.index', ['f' => ['SupplierCode' => ['q' => $result->SupplierCode, 'eq' => 1]]])
            ->with('status', $result->Message);
    }

    public function edit(int $branch, string $code): View
    {
        $this->guard();

        return view('partners::suppliers.edit', $this->reads->supplier($branch, $code));
    }

    public function update(int $branch, string $code, SaveSupplierRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->saveSupplier($branch, $code, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.suppliers.show', ['branch' => $branch, 'code' => trim($code)])->with('status', $result->Message);
    }

    public function reverse(int $branch, string $code, string $write, ReverseWriteRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->reverse($write, (string) $request->validated('Reason'));
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.suppliers.show', ['branch' => $branch, 'code' => trim($code)])->with('status', $result->Message);
    }

    private function guard(): void
    {
        abort_unless(PartnerWriteService::live(), 404);
    }
}
