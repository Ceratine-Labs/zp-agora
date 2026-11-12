<?php

namespace Modules\Partners\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The trading partners' named write (T024; CLAUDE.md rule 1, PM decision
 * 01a1cecf-8e08): supplier and customer edits written back to BRN_Suppliers
 * and BRN_Customers, new suppliers and customers inserted there, and an edit
 * put back — each through its own procedure, which holds every rule. This
 * passes them the caller's identity, permission and granted sites, resolved
 * here and never taken from the form, and the literal switch
 * config('partners.write_mode').
 */
class PartnerWriteService
{
    public function __construct(private ProcedureService $procedures, private BranchContext $context) {}

    /** Read from the LITERAL config('partners.write_mode') and nothing else. */
    public static function writeMode(): string
    {
        return config('partners.write_mode') === 'live' ? 'live' : 'off';
    }

    public static function live(): bool
    {
        return self::writeMode() === 'live';
    }

    /** Whether the signed-in person may $action (edit, create) $resource (suppliers, customers) right now. */
    public static function may(string $resource, string $action): bool
    {
        return self::live() && (bool) Auth::user()?->can("partners.{$resource}.{$action}");
    }

    /**
     * What Agora has written for one supplier (every site) or customer, one
     * collection per press, newest first.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function changes(string $kind, int $branch, string $code): Collection
    {
        return $this->procedures->call('agora.usp_Partners_GetChanges', [
            'Kind' => $kind,
            'BranchId' => $branch,
            'Code' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
        ])->groupBy(fn (object $row) => strtolower((string) $row->WriteId));
    }

    /** @return array{APS: ?object, APE: ?object} the proposed next code in each series */
    public function nextSupplierCodes(): array
    {
        return [
            'APS' => $this->procedures->call('agora.usp_Partners_NextSupplierCode', ['Series' => 'APS'])->first(),
            'APE' => $this->procedures->call('agora.usp_Partners_NextSupplierCode', ['Series' => 'APE'])->first(),
        ];
    }

    public function nextCustomerCode(int $branch): ?string
    {
        if (! $this->context->maySee($branch)) {
            return null;
        }

        return $this->procedures->call('agora.usp_Partners_NextCustomerCode', ['BranchId' => $branch])->first()?->NextCode;
    }

    /** @param  array<string, mixed>  $input */
    public function saveSupplier(int $branch, string $code, array $input): object
    {
        return $this->procedures->write('agora.usp_Partners_SaveSupplier', [
            'BranchId' => $branch,
            'SupplierCode' => trim($code),
            'SupplierName' => $input['SupplierName'],
            'ContactPerson' => $input['ContactPerson'] ?? null,
            'Department' => $input['Department'] ?? null,
            'IsActive' => (int) ($input['IsActive'] ?? 0),
            'IsCash' => (int) ($input['IsCash'] ?? 0),
            'IsStock' => (int) ($input['IsStock'] ?? 0),
            'ExpectedSupplierName' => $input['ExpectedSupplierName'] ?? null,
            'ExpectedContactPerson' => $input['ExpectedContactPerson'] ?? null,
            'ExpectedDepartment' => $input['ExpectedDepartment'] ?? null,
            'ExpectedIsActive' => (int) $input['ExpectedIsActive'],
            'ExpectedIsCash' => (int) $input['ExpectedIsCash'],
            'ExpectedIsStock' => (int) $input['ExpectedIsStock'],
        ] + $this->common('partners.suppliers.edit', $input));
    }

    /** @param  array<string, mixed>  $input */
    public function addSupplier(array $input): object
    {
        return $this->procedures->write('agora.usp_Partners_AddSupplier', [
            'Series' => $input['Series'],
            'SupplierName' => $input['SupplierName'],
            'ContactPerson' => $input['ContactPerson'] ?? null,
            'Department' => $input['Department'] ?? null,
            'IsStock' => (int) ($input['IsStock'] ?? 0),
            'IsCash' => (int) ($input['IsCash'] ?? 0),
        ] + $this->common('partners.suppliers.create', $input));
    }

    /** @param  array<string, mixed>  $input */
    public function saveCustomer(int $branch, string $code, array $input): object
    {
        $params = ['BranchId' => $branch, 'CustomerCode' => trim($code)];
        foreach (['CustomerName', 'ContactPerson', 'Department', 'JobTitle', 'Telephone', 'Telephone2'] as $field) {
            $params[$field] = $input[$field] ?? null;
            $params['Expected'.$field] = $input['Expected'.$field] ?? null;
        }

        return $this->procedures->write('agora.usp_Partners_SaveCustomer', $params + $this->common('partners.customers.edit', $input));
    }

    /** @param  array<string, mixed>  $input */
    public function addCustomer(array $input): object
    {
        return $this->procedures->write('agora.usp_Partners_AddCustomer', [
            'BranchId' => (int) $input['BranchId'],
            'CustomerName' => $input['CustomerName'],
            'AccountNo' => $input['AccountNo'],
            'ContactPerson' => $input['ContactPerson'] ?? null,
            'Department' => $input['Department'] ?? null,
            'JobTitle' => $input['JobTitle'] ?? null,
            'Telephone' => $input['Telephone'] ?? null,
            'Telephone2' => $input['Telephone2'] ?? null,
        ] + $this->common('partners.customers.create', $input));
    }

    public function reverse(string $writeId, string $reason): object
    {
        $user = Auth::user();

        return $this->procedures->write('agora.usp_Partners_ReverseWrite', [
            'WriteId' => $writeId,
            'Reason' => $reason,
            'UserId' => $user?->Id,
            'AuthorizedSuppliers' => (int) ($user?->can('partners.suppliers.edit') ?? false),
            'AuthorizedCustomers' => (int) ($user?->can('partners.customers.edit') ?? false),
            'WriteMode' => self::writeMode(),
            'AllowedBranchIds' => $this->allowedCsv(),
            'ReasonMin' => (int) config('partners.reason_min', 10),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function common(string $permission, array $input): array
    {
        $user = Auth::user();

        return [
            'Reason' => $input['Reason'] ?? null,
            'UserId' => $user?->Id,
            'Authorized' => (int) ($user?->can($permission) ?? false),
            'WriteMode' => self::writeMode(),
            'AllowedBranchIds' => $this->allowedCsv(),
            'ReasonMin' => (int) config('partners.reason_min', 10),
        ];
    }

    private function allowedCsv(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
