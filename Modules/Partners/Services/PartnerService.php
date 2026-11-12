<?php

namespace Modules\Partners\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The trading partners' reads (T024). Every rule is in the procedures; this
 * hands them the caller's granted sites and permissions, resolved here and
 * never taken from the request.
 *
 * There is no save. The suppliers and customers are PumpIT's BRN_Suppliers
 * and BRN_Customers, and where an edit made in Agora would land is not
 * decided yet. checkCode() and nextSupplierCode() are the two reads a save
 * path will need, built and tested now so that it inherits them.
 */
class PartnerService
{
    public function __construct(private ProcedureService $procedures, private BranchContext $context) {}

    /**
     * One supplier at one site, and the same code at the caller's other
     * sites; a 404 when there is none or the site is not the caller's.
     *
     * @return array{supplier: object, elsewhere: Collection<int, object>}
     */
    public function supplier(int $branch, string $code): array
    {
        abort_unless($this->context->maySee($branch), 404);

        $sets = $this->procedures->callSets('agora.usp_Partners_GetSupplier', [
            'BranchId' => $branch,
            'SupplierCode' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
        ]);

        $supplier = ($sets[0] ?? collect())->first();
        abort_if($supplier === null, 404);

        return ['supplier' => $supplier, 'elsewhere' => $sets[1] ?? collect()];
    }

    /** One account customer at one site; a 404 when there is none or the site is not the caller's. */
    public function customer(int $branch, string $code): object
    {
        abort_unless($this->context->maySee($branch), 404);

        $customer = $this->procedures->call('agora.usp_Partners_GetCustomer', [
            'BranchId' => $branch,
            'CustomerCode' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
        ])->first();

        abort_if($customer === null, 404);

        return $customer;
    }

    /**
     * Suppliers and customers matching $q, for the command palette: each kind
     * only for whoever may view it, each hit with the URL it opens.
     *
     * @return array<int, array{kind: string, code: string, label: string, detail: string, url: string}>
     */
    public function search(string $q): array
    {
        $suppliers = Gate::allows('partners.suppliers.view');
        $customers = Gate::allows('partners.customers.view');

        if (! $suppliers && ! $customers) {
            return [];
        }

        return $this->procedures->call('agora.usp_Partners_Search', [
            'Search' => $q,
            'AllowedBranchIds' => $this->allowedCsv(),
            'IncludeSuppliers' => (int) $suppliers,
            'IncludeCustomers' => (int) $customers,
            'Limit' => (int) config('partners.search_limit', 12),
        ])->map(fn (object $hit): array => [
            'kind' => (string) $hit->Kind,
            'code' => (string) $hit->Code,
            'label' => (string) ($hit->Name ?? $hit->Code),
            'detail' => (string) $hit->Detail,
            'url' => $this->hitUrl($hit),
        ])->all();
    }

    /**
     * The duplicate-code guard (agora.usp_Partners_CheckCode) — what a new
     * code would collide with. Findings, not a refusal: a save path calls the
     * procedure itself with @Throw = 1 inside its transaction.
     *
     * @return Collection<int, object> rows of (Code, Severity, Message)
     */
    public function checkCode(string $kind, int $branch, string $code, ?string $name = null, ?string $accountNo = null): Collection
    {
        return $this->procedures->call('agora.usp_Partners_CheckCode', [
            'Kind' => $kind,
            'BranchId' => $branch,
            'Code' => $code,
            'Name' => $name,
            'AccountNo' => $accountNo,
            'Throw' => 0,
        ]);
    }

    /** The next free code in a supplier series — proposed, not reserved. */
    public function nextSupplierCode(string $series = 'APS'): ?string
    {
        $row = $this->procedures->call('agora.usp_Partners_NextSupplierCode', ['Series' => $series])->first();

        return $row?->NextCode;
    }

    /**
     * A customer opens its own page. A supplier at exactly one of the caller's
     * sites opens that site's page; one at several opens the list narrowed to
     * the code at every site, because that IS the supplier.
     */
    private function hitUrl(object $hit): string
    {
        if ($hit->Kind === 'customer') {
            return route('app.setup.customers.show', ['branch' => $hit->BranchId, 'code' => trim((string) $hit->Code)]);
        }

        if ($hit->BranchId !== null) {
            return route('app.setup.suppliers.show', ['branch' => $hit->BranchId, 'code' => trim((string) $hit->Code)]);
        }

        return route('app.setup.suppliers.index', ['f' => ['SupplierCode' => ['q' => trim((string) $hit->Code), 'eq' => 1]]]);
    }

    private function allowedCsv(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
