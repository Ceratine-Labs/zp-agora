<?php

namespace Modules\People\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The people master's named write (T023; CLAUDE.md rule 1, PM decision
 * 01a1ceba-4d74): BRN_Employee IsActive / PostNo / NickName, BRN_EmployeeArea,
 * and new BRN_EmployeePost rows — each through its own procedure, which holds
 * every rule. This passes them the caller's identity, permission and granted
 * sites, resolved here and never taken from the form, and the literal switch
 * config('people.write_mode').
 */
class EmployeeWriteService
{
    public function __construct(private ProcedureService $procedures, private BranchContext $context) {}

    /** Read from the LITERAL config('people.write_mode') and nothing else. */
    public static function writeMode(): string
    {
        return config('people.write_mode') === 'live' ? 'live' : 'off';
    }

    public static function live(): bool
    {
        return self::writeMode() === 'live';
    }

    /**
     * The edit form: the employee as PumpIT holds it now, the branch's posts,
     * and every counting area with the ones assigned ticked. 404 when there is
     * none or the site is not the caller's.
     *
     * @return array{employee: object, posts: Collection<int, object>, areas: Collection<int, object>}
     */
    public function form(int $branch, string $code): array
    {
        abort_unless($this->context->maySee($branch), 404);

        $sets = $this->procedures->callSets('agora.usp_People_GetEmployeeForm', [
            'BranchId' => $branch,
            'EmployeeCode' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
        ]);

        $employee = ($sets[0] ?? collect())->first();
        abort_if($employee === null, 404);

        return ['employee' => $employee, 'posts' => $sets[1] ?? collect(), 'areas' => $sets[2] ?? collect()];
    }

    /** @return Collection<int, object> newest first */
    public function changes(int $branch, string $code): Collection
    {
        return $this->procedures->call('agora.usp_People_GetEmployeeChanges', [
            'BranchId' => $branch,
            'EmployeeCode' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
        ]);
    }

    /** @param  array<string, mixed>  $input */
    public function saveEmployee(int $branch, string $code, array $input): object
    {
        return $this->procedures->write('agora.usp_People_SaveEmployee', [
            'BranchId' => $branch,
            'EmployeeCode' => trim($code),
            'IsActive' => (int) ($input['IsActive'] ?? 0),
            'PostNo' => (int) $input['PostNo'],
            'NickName' => $input['NickName'] ?? null,
            'ExpectedIsActive' => (int) $input['ExpectedIsActive'],
            'ExpectedPostNo' => (int) $input['ExpectedPostNo'],
            'ExpectedNickName' => $input['ExpectedNickName'] ?? null,
        ] + $this->common('people.employees.edit', $input));
    }

    /** @param  array<string, mixed>  $input */
    public function saveAreas(int $branch, string $code, array $input): object
    {
        return $this->procedures->write('agora.usp_People_SaveEmployeeAreas', [
            'BranchId' => $branch,
            'EmployeeCode' => trim($code),
            'AreasJson' => json_encode(array_values(array_map('intval', $input['Areas'] ?? []))),
            'ExpectedAreasJson' => json_encode(array_values(array_map('intval', (array) json_decode((string) $input['ExpectedAreas'], true)))),
        ] + $this->common('people.areas.edit', $input));
    }

    /** @param  array<string, mixed>  $input */
    public function addPost(int $branch, array $input): object
    {
        return $this->procedures->write('agora.usp_People_AddPost', [
            'BranchId' => $branch,
            'PostDescription' => $input['PostDescription'],
            'StockControl' => (int) ($input['StockControl'] ?? 0),
        ] + $this->common('people.posts.save', $input));
    }

    public function reverse(int $branch, string $writeId, string $reason): object
    {
        $user = Auth::user();

        return $this->procedures->write('agora.usp_People_ReverseWrite', [
            'BranchId' => $branch,
            'WriteId' => $writeId,
            'Reason' => $reason,
            'UserId' => $user?->Id,
            'AuthorizedEmployee' => (int) ($user?->can('people.employees.edit') ?? false),
            'AuthorizedAreas' => (int) ($user?->can('people.areas.edit') ?? false),
            'WriteMode' => self::writeMode(),
            'AllowedBranchIds' => $this->allowedCsv(),
            'ReasonMin' => (int) config('people.reason_min', 10),
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
            'ReasonMin' => (int) config('people.reason_min', 10),
        ];
    }

    private function allowedCsv(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
