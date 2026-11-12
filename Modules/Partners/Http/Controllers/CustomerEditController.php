<?php

namespace Modules\Partners\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Partners\Http\Requests\AddCustomerRequest;
use Modules\Partners\Http\Requests\ReverseWriteRequest;
use Modules\Partners\Http\Requests\SaveCustomerRequest;
use Modules\Partners\Services\PartnerService;
use Modules\Partners\Services\PartnerWriteService;

/**
 * Setup → Trading rules → Account customers (T024): change a customer, add
 * one, and put a change back — a NAMED WRITE to PumpIT's BRN_Customers
 * (CLAUDE.md rule 1, PM decision 01a1cecf-8e08). The credit limit, the cell
 * and the statement address are not here: the first two are the till's, the
 * last is Debtors'. With partners.write_mode off these routes are 404.
 */
class CustomerEditController extends Controller
{
    public function __construct(private PartnerService $reads, private PartnerWriteService $writes, private BranchContext $context) {}

    public function create(Request $request): View
    {
        $this->guard();
        $branches = Branch::query()->visibleTo($this->context->allowed())->ordered()->get();
        $chosen = $request->query('branch');
        $branch = is_numeric($chosen) ? (int) $chosen : (int) old('BranchId', '0');

        return view('partners::customers.create', [
            'branches' => $branches,
            'branch' => $branch,
            'nextCode' => $branch > 0 ? $this->writes->nextCustomerCode($branch) : null,
        ]);
    }

    public function store(AddCustomerRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->addCustomer($request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.customers.show', ['branch' => (int) $request->validated('BranchId'), 'code' => $result->CustomerCode])
            ->with('status', $result->Message);
    }

    public function edit(int $branch, string $code): View
    {
        $this->guard();
        $customer = $this->reads->customer($branch, $code);
        abort_if((bool) $customer->IsPaymentControl, 404);

        return view('partners::customers.edit', ['customer' => $customer]);
    }

    public function update(int $branch, string $code, SaveCustomerRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->saveCustomer($branch, $code, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.customers.show', ['branch' => $branch, 'code' => trim($code)])->with('status', $result->Message);
    }

    public function reverse(int $branch, string $code, string $write, ReverseWriteRequest $request): RedirectResponse
    {
        $this->guard();

        try {
            $result = $this->writes->reverse($write, (string) $request->validated('Reason'));
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.customers.show', ['branch' => $branch, 'code' => trim($code)])->with('status', $result->Message);
    }

    private function guard(): void
    {
        abort_unless(PartnerWriteService::live(), 404);
    }
}
