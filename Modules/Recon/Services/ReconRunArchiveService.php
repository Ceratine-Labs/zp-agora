<?php

namespace Modules\Recon\Services;

use App\Grid\GridResult;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Carries the displayed scope intact; eligibility and updates remain in SQL. */
class ReconRunArchiveService
{
    public function __construct(private ProcedureService $procedures) {}

    public function scope(GridResult $grid, Request $request, BranchContext $context): ?string
    {
        if ($request->query('validity') !== 'invalid' || ! $request->user()?->can('recon.runs.close')) {
            return null;
        }

        $area = $request->query('area');
        $area = is_string($area) && array_key_exists($area, config('recon.areas', [])) ? $area : null;
        $parameters = Arr::only($grid->gridQuery->procedureParameters(), ['BranchIds', 'DateFrom', 'DateTo', 'Search']);
        $parameters += [
            'FiltersJson' => $grid->gridQuery->filtersJson(),
            'ReconArea' => $area,
            'MineOnly' => (int) ($request->query('scope', 'mine') !== 'all'),
            // Invalid is its own view, independent of the Open/Everything switch.
            'OpenOnly' => 0,
        ];

        return Crypt::encryptString(json_encode([
            'version' => 1,
            'actor' => (int) $request->user()->getAuthIdentifier(),
            'workspace' => $context->workspace(),
            'branch' => $context->isBranchWorkspace() ? $context->id() : null,
            'expires' => now()->addHour()->timestamp,
            'parameters' => $parameters,
            'return_query' => Arr::only($grid->query, [
                'scope', 'show', 'validity', 'branches', 'from', 'to', 'q', 'f',
                'page', 'size', 'sort', 'dir', 'ws', 'branch_id',
            ]),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * No posted filter can silently broaden the question shown on screen.
     * Fresh grants narrow it further, and SQL rechecks every run before closing.
     *
     * @param  array<int, int|string>  $items
     * @return array{archived: int, skipped: int, redirect: string}
     */
    public function archive(string $token, string $mode, array $items, Request $request, BranchContext $context): array
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('recon.runs.view') && $user->can('recon.runs.close'), 403);

        try {
            $scope = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalidScope();
        }

        if (! is_array($scope) || ($scope['version'] ?? null) !== 1
            || ($scope['actor'] ?? null) !== (int) $request->user()->getAuthIdentifier()
            || ($scope['workspace'] ?? null) !== $context->workspace()
            || ($scope['branch'] ?? null) !== ($context->isBranchWorkspace() ? $context->id() : null)
            || ! is_int($scope['expires'] ?? null) || $scope['expires'] < now()->timestamp
            || ! is_array($scope['parameters'] ?? null) || ! is_array($scope['return_query'] ?? null)) {
            throw $this->invalidScope();
        }

        $parameters = $scope['parameters'];
        $keys = ['BranchIds', 'DateFrom', 'DateTo', 'Search', 'FiltersJson', 'ReconArea', 'MineOnly', 'OpenOnly'];
        if (count($parameters) !== count($keys) || array_diff($keys, array_keys($parameters)) !== []
            || ! in_array($parameters['MineOnly'], [0, 1], true) || $parameters['OpenOnly'] !== 0) {
            throw $this->invalidScope();
        }
        foreach (['BranchIds', 'DateFrom', 'DateTo', 'Search', 'FiltersJson', 'ReconArea'] as $key) {
            if ($parameters[$key] !== null && ! is_string($parameters[$key])) {
                throw $this->invalidScope();
            }
        }
        if (($parameters['ReconArea'] !== null && ! array_key_exists($parameters['ReconArea'], config('recon.areas', [])))
            || ! in_array($mode, ['selected', 'all'], true)
            || ($mode === 'all' && $items !== []) || ($mode === 'selected' && ($items === [] || count($items) > 200))) {
            throw $this->invalidScope();
        }
        foreach ($items as $id) {
            if ((! is_int($id) && ! is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw $this->invalidScope();
            }
        }

        $parameters['UserId'] = (int) $request->user()->getAuthIdentifier();
        $parameters['AllowedBranchIds'] = $context->allowed() === [] ? null : implode(',', $context->allowed());
        $parameters['RunIdsJson'] = $mode === 'selected'
            ? json_encode(array_values(array_unique(array_map('intval', $items))), JSON_THROW_ON_ERROR)
            : null;

        $status = $this->procedures->write('usp_Recon_ArchiveInvalidRuns', $parameters);
        $query = $scope['return_query'];
        $query['ws'] = $context->workspace();
        // A removed last page should return to results, not an empty high page.
        unset($query['page']);
        $redirect = $parameters['ReconArea'] === null
            ? route('app.recon.index', $query)
            : route('app.recon.runs', ['area' => $parameters['ReconArea']] + $query);

        return [
            'archived' => (int) ($status->Archived ?? $status->Id),
            'skipped' => (int) ($status->Skipped ?? 0),
            'redirect' => $redirect,
        ];
    }

    private function invalidScope(): ValidationException
    {
        return ValidationException::withMessages([
            'scope_token' => 'The runs scope has expired or changed. Reload the list before archiving.',
        ]);
    }
}
