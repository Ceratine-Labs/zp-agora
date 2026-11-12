<?php

namespace Modules\Core\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Requests\SaveSettingRequest;
use Modules\Core\Services\SettingService;

/**
 * Setup → The business → System defaults and thresholds (T026).
 *
 * The listing, one setting, and its three changes, each behind its own
 * permission (feature-rules §4) — at the route, on the button, and in
 * agora.usp_Core_SaveSetting:
 *
 *   edit     change a value: the group's (BranchId = the group entity) or a site's
 *   save     give a site its own value
 *   delete   take a site's own value away
 *
 * The group value is never created or deleted here: every setting has one
 * from the seeder, and a threshold with no value is a gap, not a choice.
 */
class SettingController extends Controller
{
    public function __construct(private SettingService $settings) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('core::setup.settings.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.settings'), $request, $request->user()?->Id),
        ]);
    }

    public function show(string $key, Request $request): View
    {
        return view($request->ajax() ? 'core::setup.settings.detail' : 'core::setup.settings.show', $this->settings->get($key));
    }

    /** Change the group value, or a site's own (?branch=). */
    public function edit(string $key, Request $request): View
    {
        $data = $this->settings->get($key);
        $group = $this->settings->groupBranchId();
        $branchId = $request->integer('branch', $group);

        $row = $branchId === $group
            ? ($data['setting']->HasGroupRow ? (object) ['BranchId' => $group, 'BranchName' => null, 'Value' => $data['setting']->GroupValue, 'Version' => $data['setting']->Version] : null)
            : $data['overrides']->firstWhere('BranchId', $branchId);
        abort_if($row === null || $data['setting']->IsReadOnly, 404);

        return view('core::setup.settings.form', $data + ['row' => $row, 'mode' => 'edit']);
    }

    /** Give a site its own value. */
    public function create(string $key): View
    {
        $data = $this->settings->get($key);
        abort_if(! $data['setting']->AllowsBranch || $data['setting']->IsReadOnly, 404);

        return view('core::setup.settings.form', $data + ['row' => null, 'mode' => 'create']);
    }

    public function update(string $key, SaveSettingRequest $request): RedirectResponse
    {
        return $this->save($key, (int) $request->validated('BranchId'), 'edit', $request);
    }

    public function store(string $key, SaveSettingRequest $request): RedirectResponse
    {
        return $this->save($key, (int) $request->validated('BranchId'), 'create', $request);
    }

    public function destroy(string $key, int $branch, SaveSettingRequest $request): RedirectResponse
    {
        return $this->save($key, $branch, 'remove', $request);
    }

    /** @param  'edit'|'create'|'remove'  $mode */
    private function save(string $key, int $branchId, string $mode, SaveSettingRequest $request): RedirectResponse
    {
        $input = $request->validated();

        try {
            $result = $this->settings->save($key, $branchId, $mode, $mode === 'remove' ? null : ($input['Value'] ?? null),
                $input['ExpectedVersion'] ?? null, $input['Reason']);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.settings.show', ['key' => $key])->with('status', $result->Message);
    }
}
